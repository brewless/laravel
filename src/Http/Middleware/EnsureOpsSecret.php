<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lets a request through only with the ops secret, and only on a container
 * that was started with one. Everything else gets a plain 404, so the ops
 * routes cannot be told apart from routes that do not exist.
 *
 * The secret comes in a header (a release) or in the JSON body (a trigger of
 * the provider can only send a body).
 *
 * An address that keeps guessing is shut out for a minute, with the same
 * 404, so the secret cannot be found by trying. The guesses are counted in
 * the application's cache; when that cannot be reached, the secret alone
 * decides. The cache of a new application may live in a database whose
 * tables only the release commands behind this door make.
 */
final class EnsureOpsSecret
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'brewless-ops-secret:'.$request->ip();

        abort_if($this->counted(static fn (): bool => RateLimiter::tooManyAttempts($key, (int) config('brewless.ops.max_failures'))) === true, 404);

        $secret = config('brewless.ops.secret');
        $given = $request->headers->get((string) config('brewless.ops.secret_header')) ?? $request->json('secret');

        if (! is_string($secret) || $secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            $this->counted(static fn (): int => RateLimiter::hit($key));

            abort(404);
        }

        return $next($request);
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
