<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restores the visitor's hostname and address on requests that came through
 * the edge.
 *
 * The edge sends the container's own domain as Host and the real hostname in
 * a header of its own. That header is believed only together with the shared
 * edge secret, and only for the application's own domains and their
 * subdomains. Anything else keeps the Host it arrived with.
 *
 * The visitor's address travels in X-Forwarded-For, to which every proxy on
 * the way appends what it saw. A visitor can put anything in front of that,
 * so only the last entries count: as many as there are proxies
 * (`brewless.edge.hops`), and only with the secret. Without it the header is
 * dropped, so a request that did not come through the edge cannot choose its
 * own address either. An application without an edge secret has no edge, and
 * its headers are left alone.
 */
final class TrustEdgeHost
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('brewless.edge.secret');

        if (! is_string($secret) || $secret === '') {
            return $next($request);
        }

        $given = $request->headers->get((string) config('brewless.edge.secret_header'));
        $fromEdge = is_string($given) && hash_equals($secret, $given);
        $host = $fromEdge ? $this->edgeHost($request) : null;

        if ($host !== null) {
            $request->headers->set('Host', $host);
            $request->server->set('HTTP_HOST', $host);
            $request->server->set('SERVER_NAME', $host);
        }

        $address = $fromEdge ? $this->visitorAddress($request) : null;

        if ($address === null) {
            $request->headers->remove('X-Forwarded-For');
            $request->server->remove('HTTP_X_FORWARDED_FOR');
        } else {
            $request->headers->set('X-Forwarded-For', $address);
            $request->server->set('HTTP_X_FORWARDED_FOR', $address);
        }

        // The secret has done its job; nothing further down needs to see it.
        $request->headers->remove((string) config('brewless.edge.secret_header'));

        return $next($request);
    }

    private function edgeHost(Request $request): ?string
    {
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

    /**
     * The address the edge saw: the entry the proxies' own entries sit
     * behind. Null when the header is too short to hold it, which a request
     * through the edge never is.
     */
    private function visitorAddress(Request $request): ?string
    {
        $forwarded = array_map(trim(...), explode(',', (string) $request->headers->get('X-Forwarded-For')));
        $hops = max(1, (int) config('brewless.edge.hops'));

        if (count($forwarded) < $hops) {
            return null;
        }

        $address = $forwarded[count($forwarded) - $hops];

        return filter_var($address, FILTER_VALIDATE_IP) === false ? null : $address;
    }
}
