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
 * The secret is compared first: a request with the right secret, or with a
 * job whose signature holds, always passes. Only a failed attempt is
 * counted, per address, in the application's cache, and an address that
 * reaches `ops.max_failures` in a minute is logged once. It is not shut out:
 * behind the provider's front end many callers may share one address, and
 * someone guessing there would otherwise shut Brewless out of its own
 * release, tick and queue. What keeps the secret from being found by trying
 * is its length; Brewless makes it long and random. When the cache cannot be
 * reached, nothing is counted: the cache of a new application may live in a
 * database whose tables only the release commands behind this door make.
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
        $secret = config('brewless.ops.secret');
        $given = $request->headers->get((string) config('brewless.ops.secret_header')) ?? $request->json('secret');

        if (! is_string($secret) || $secret === '') {
            $this->refuse($request);
        }

        if (is_string($given) && hash_equals($secret, $given)) {
            $this->allowTheTime();

            return $next($request);
        }

        // A job the provider's queue trigger delivers carries no secret of ours,
        // only the signature this application put on it, with the key
        // every container derives from the application key. One whose signature
        // does not hold is answered, not run: refused, the provider would
        // deliver it again every half minute, without end.
        if ($given === null && $request->routeIs('brewless.ops.trigger')) {
            $payload = json_decode($request->getContent(), true);

            if (is_array($payload) && JobSignature::isJob($payload)) {
                if (! JobSignature::holdsForApplication($payload)) {
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

        $this->refuse($request);
    }

    /**
     * Count the failed attempt of this address and answer as if the route did
     * not exist. The address that reaches the limit is logged, once a minute.
     */
    private function refuse(Request $request): never
    {
        $failures = $this->counted(static fn (): int => RateLimiter::hit('brewless-ops-secret:'.$request->ip()));

        if ($failures === (int) config('brewless.ops.max_failures')) {
            Log::warning('brewless: an address keeps giving a wrong ops secret', ['ip' => $request->ip()]);
        }

        abort(404);
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
