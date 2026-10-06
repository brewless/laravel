<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restores the visitor's hostname on requests that came through the edge.
 *
 * The edge sends the container's own domain as Host and the real hostname in
 * a header of its own. That header is believed only together with the shared
 * edge secret, and only for the application's own domains and their
 * subdomains. Anything else keeps the Host it arrived with.
 */
final class TrustEdgeHost
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->edgeHost($request);

        if ($host !== null) {
            $request->headers->set('Host', $host);
            $request->server->set('HTTP_HOST', $host);
            $request->server->set('SERVER_NAME', $host);
        }

        // The secret has done its job; nothing further down needs to see it.
        $request->headers->remove((string) config('brewless.edge.secret_header'));

        return $next($request);
    }

    private function edgeHost(Request $request): ?string
    {
        $secret = config('brewless.edge.secret');
        $given = $request->headers->get((string) config('brewless.edge.secret_header'));

        if (! is_string($secret) || $secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            return null;
        }

        $host = strtolower((string) $request->headers->get((string) config('brewless.edge.host_header')));

        if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        foreach ((array) config('brewless.edge.domains') as $domain) {
            if (is_string($domain) && $domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return $host;
            }
        }

        return null;
    }
}
