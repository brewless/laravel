<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only with the ops secret, and only on a container
 * that was started with one. Everything else gets a plain 404, so the ops
 * routes cannot be told apart from routes that do not exist.
 *
 * The secret comes in a header (a release) or in the JSON body (a trigger of
 * the provider can only send a body).
 */
final class EnsureOpsSecret
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('brewless.ops.secret');
        $given = $request->headers->get((string) config('brewless.ops.secret_header')) ?? $request->json('secret');

        abort_unless(is_string($secret) && $secret !== '' && is_string($given) && hash_equals($secret, $given), 404);

        return $next($request);
    }
}
