<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Brewless\Laravel\Queue\JobSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lets a request through only with the ops secret, and only on a container
 * that was started with one. Everything else gets a plain 404, so the ops
 * routes cannot be told apart from routes that do not exist.
 *
 * The secret comes in a header (a release) or in the JSON body (a trigger of
 * the provider can only send a body). A queue trigger of the provider sends
 * the job itself, signed by this application (JobSignature).
 *
 * An address that keeps guessing is shut out for a minute, with the same
 * 404, so the secret cannot be found by trying. The guesses are counted in
 * the application's cache; when that cannot be reached, the secret alone
 * decides. The cache of a new application may live in a database whose
 * tables only the release commands behind this door make.
 *
 * PHP ends a web request after 30 seconds by default (`max_execution_time`
 * in php.ini-production), shorter than release commands, a queue run or a
 * command may take. A request that is let through gets `ops.time_limit`.
 */
final class EnsureOpsSecret
{
    /** Where a signed job from the provider's queue trigger is handed to the route. */
    public const string SIGNED_JOB = 'brewless.signed_job';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'brewless-ops-secret:'.$request->ip();

        abort_if($this->counted(static fn (): bool => RateLimiter::tooManyAttempts($key, (int) config('brewless.ops.max_failures'))) === true, 404);

        $secret = config('brewless.ops.secret');
        $given = $request->headers->get((string) config('brewless.ops.secret_header')) ?? $request->json('secret');

        // A job the provider's queue trigger delivers carries no secret of ours,
        // only the signature this application put on it. One whose signature
        // does not hold is answered, not run: refused, the provider would
        // deliver it again every half minute, without end.
        if ($given === null && is_string($secret) && $secret !== '' && $request->routeIs('brewless.ops.trigger')) {
            $payload = json_decode($request->getContent(), true);

            if (is_array($payload) && JobSignature::isJob($payload)) {
                if (! JobSignature::holds($payload, $secret)) {
                    // What was different, never what the job carries: whether it was
                    // signed at all, which keys it has in which order, its size, and
                    // whether the provider wrote it again with another encoder.
                    Log::warning('brewless: a queue message without a valid signature was dropped', [
                        'uuid' => $payload['uuid'],
                        'signed' => is_string($payload[JobSignature::KEY] ?? null),
                        'keys' => array_keys($payload),
                        'bytes' => strlen($request->getContent()),
                        'reencoded' => json_encode($payload, JSON_UNESCAPED_UNICODE) !== $request->getContent(),
                        'content_type' => $request->headers->get('Content-Type'),
                    ]);

                    return response()->json(['ok' => false, 'dropped' => true]);
                }

                $request->attributes->set(self::SIGNED_JOB, $payload);
                $this->allowTheTime();

                return $next($request);
            }
        }

        if (! is_string($secret) || $secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            $this->counted(static fn (): int => RateLimiter::hit($key));

            abort(404);
        }

        $this->allowTheTime();

        return $next($request);
    }

    /**
     * Raise PHP's limit for this request to `ops.time_limit`. Never lowered:
     * without a limit (0), or with a longer one, it stays as it is.
     */
    private function allowTheTime(): void
    {
        $limit = (int) ini_get('max_execution_time');
        $wanted = (int) config('brewless.ops.time_limit');

        if ($limit > 0 && $wanted > $limit) {
            set_time_limit($wanted);
        }
    }

    /**
     * Ask the counter of guesses, or null when its cache cannot be reached.
     *
     * @template T
     *
     * @param  Closure(): T  $ask
     * @return T|null
     */
    private function counted(Closure $ask): mixed
    {
        try {
            return $ask();
        } catch (Throwable) {
            return null;
        }
    }
}
