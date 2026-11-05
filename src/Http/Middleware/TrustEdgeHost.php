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
 * While that secret is being replaced the container holds two: the one in
 * force and the one before it (`BREWLESS_EDGE_SECRET_PREVIOUS`). Both are
 * believed, so the edge and the container never have to change in the same
 * instant — whichever of the two the edge is sending, the hostname is put
 * back. The one before it is taken away again once the edge sends the new
 * one, and holding two is a state that lasts minutes, not a setting to leave
 * standing.
 *
 * The visitor's address travels in X-Forwarded-For, to which every proxy on
 * the way appends what it saw. A visitor can put anything in front of that,
 * so only the last entries count: as many as there are proxies
 * (`brewless.edge.hops`), and only with the secret. Without it the header is
 * dropped, so a request that did not come through the edge cannot choose its
 * own address either.
 *
 * An application without an edge secret has no edge in front, but still the
 * provider's own front end, which appends the address it saw as the last
 * entry. Only that entry is kept: what a visitor wrote in front of it is not
 * believed, so rate limits count the real visitor here too.
 *
 * The edge forces HTTPS on every request and reaches the container over a
 * connection of its own, so a request with the secret was HTTPS for the
 * visitor whatever the container saw. It is marked so, and the URLs the
 * application generates (assets, links, redirects) start with https://
 * without the application having to trust a proxy.
 *
 * An environment without an edge is visited on the container's own address
 * at Scaleway (`<name>.functions.fnc.<region>.scw.cloud`). TLS ends at
 * Scaleway's front end there too, so the request reaches the container as
 * http, and is marked as HTTPS on its hostname. A name under that domain
 * reaches only the container it belongs to, so a request carrying it came
 * through Scaleway's front end, and that front end lets nothing but HTTPS
 * through to a container Brewless provisioned (`http_option: redirected`).
 * X-Forwarded-Proto is not believed instead: a visitor can write it, and the
 * package cannot tell whether Scaleway overwrote it.
 */
final class TrustEdgeHost
{
    /** The container's own address at Scaleway: one name under the region's functions domain. */
    private const string CONTAINER_ADDRESS = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.functions\.fnc\.[a-z]{2}-[a-z]{3}\.scw\.cloud$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = self::secrets();

        if ($secrets === []) {
            if ($this->onContainerAddress($request)) {
                $this->markSecure($request);
            }

            $this->keepOnly($request, $this->visitorAddress($request));

            return $next($request);
        }

        $given = $request->headers->get((string) config('brewless.edge.secret_header'));
        $fromEdge = is_string($given) && self::accepted($secrets, $given);
        $host = $fromEdge ? $this->edgeHost($request) : null;

        if ($host !== null) {
            $request->headers->set('Host', $host);
            $request->server->set('HTTP_HOST', $host);
            $request->server->set('SERVER_NAME', $host);
        }

        if ($fromEdge || $this->onContainerAddress($request)) {
            $this->markSecure($request);
        }

        $this->keepOnly($request, $fromEdge ? $this->visitorAddress($request) : null);

        // The secret has done its job; nothing further down needs to see it.
        $request->headers->remove((string) config('brewless.edge.secret_header'));

        return $next($request);
    }

    /**
     * The secrets a request from the edge may carry: the one in force, and
     * the one before it while it is being replaced. The same value given
     * twice counts once.
     *
     * Public because it is also what the application reports about itself
     * (`Ops\Runtime::status()`): how many it holds, never which.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        $secrets = [];

        foreach ([config('brewless.edge.secret'), config('brewless.edge.previous_secret')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $secrets[] = $secret;
            }
        }

        return array_values(array_unique($secrets));
    }

    /**
     * Whether what the request carries is one of them. Every secret is
     * compared and none cuts the loop short, so the answer costs the same
     * work whichever one matched.
     *
     * @param  list<string>  $secrets
     */
    private static function accepted(array $secrets, string $given): bool
    {
        $accepted = false;

        foreach ($secrets as $secret) {
            $accepted = hash_equals($secret, $given) || $accepted;
        }

        return $accepted;
    }

    /** X-Forwarded-For as only this address, or without it. */
    private function keepOnly(Request $request, ?string $address): void
    {
        if ($address === null) {
            $request->headers->remove('X-Forwarded-For');
            $request->server->remove('HTTP_X_FORWARDED_FOR');

            return;
        }

        $request->headers->set('X-Forwarded-For', $address);
        $request->server->set('HTTP_X_FORWARDED_FOR', $address);
    }

    /**
     * HTTPS on port 443, for Symfony's own check and for an application that
     * trusts its proxies and reads the forwarded headers instead.
     */
    private function markSecure(Request $request): void
    {
        $request->server->set('HTTPS', 'on');
        $request->server->set('SERVER_PORT', 443);
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('X-Forwarded-Port', '443');
        $request->server->set('HTTP_X_FORWARDED_PROTO', 'https');
        $request->server->set('HTTP_X_FORWARDED_PORT', '443');
    }

    /** Whether the request names the container's own address at Scaleway. */
    private function onContainerAddress(Request $request): bool
    {
        $host = strtolower(rtrim((string) $request->headers->get('Host'), '.'));

        return preg_match(self::CONTAINER_ADDRESS, $host) === 1;
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
