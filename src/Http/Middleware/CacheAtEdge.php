<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the edge keep the answers that are the same for everybody.
 *
 * A serverless container sleeps only after a quiet spell (15 minutes at
 * Scaleway). Behind an edge that caches nothing, every visitor and every
 * crawler wakes it, so a public site hardly ever sleeps. This middleware gives
 * an answer `Cache-Control: public, max-age=0, s-maxage=<seconds>` when it
 * cannot depend on who asked:
 *
 * - the request is a GET or HEAD without cookies and without `Authorization`,
 *   so nothing in it says who the visitor is, and not for `/_ops/*`;
 * - the answer is a 200, 301, 308, 404 or 410, sets no cookie, and no session
 *   was started for it;
 * - the application did not set `Cache-Control` itself: whatever it chose,
 *   `no-store`, `private` or a time of its own, stays as it is;
 * - it varies on nothing but `Accept-Encoding`, the only `Vary` an edge keeps
 *   apart.
 *
 * `max-age=0` keeps it out of the browser's own cache. Brewless empties the
 * edge on every release, and it cannot empty a browser: so after a deploy no
 * visitor sees a page of the release before, and between deploys a page is at
 * most `s-maxage` seconds old.
 *
 * The edge must keep a page per hostname: one pull zone answers for a
 * domain and every subdomain. Brewless sets that on every pull zone it
 * manages.
 *
 * `brewless.edge.cache` holds the seconds (`BREWLESS_EDGE_CACHE`, 3600);
 * 0 turns it off.
 */
final class CacheAtEdge
{
    /** Answers that are the same on every request for the same address. */
    private const array CACHEABLE = [200, 301, 308, 404, 410];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $seconds = (int) config('brewless.edge.cache', 0);

        if ($seconds > 0 && $this->anonymous($request) && $this->sameForEverybody($request, $response)) {
            $response->headers->set('Cache-Control', 'public, max-age=0, s-maxage='.$seconds);
        }

        return $response;
    }

    /** Nothing in the request says who is asking. */
    private function anonymous(Request $request): bool
    {
        // The package's own routes answer to a secret in a header of their own, and never to the edge.
        if ((! $request->isMethod('GET') && ! $request->isMethod('HEAD')) || $request->is('_ops', '_ops/*')) {
            return false;
        }

        return ! $request->headers->has('Cookie') && $request->cookies->count() === 0 && ! $request->headers->has('Authorization');
    }

    private function sameForEverybody(Request $request, Response $response): bool
    {
        if (! in_array($response->getStatusCode(), self::CACHEABLE, true) || $response->headers->getCookies() !== []) {
            return false;
        }

        // A session StartSessionWhenNeeded left unstored on purpose sets no cookie and is not this visitor's.
        if ($request->hasSession() && $request->session()->isStarted() && $request->attributes->get(StartSessionWhenNeeded::SKIPPED) !== true) {
            return false;
        }

        return ! $this->chosenByApplication($response->headers) && $this->variesOnEncodingOnly($response);
    }

    /**
     * Whether the application set Cache-Control itself. Symfony fills in
     * `no-cache, private` (or `private, must-revalidate`) when nobody did,
     * and keeps what was set apart from what it computed. Laravel's error
     * pages copy that computed `no-cache, private` over as if it were set,
     * so exactly that pair counts as nobody's choice too: an application that
     * wants an answer kept away from the edge says `private` or `no-store`.
     */
    private function chosenByApplication(HeaderBag $headers): bool
    {
        /** @var array<string, bool|string> $chosen */
        $chosen = (fn (): array => $this->cacheControl)->call($headers);
        ksort($chosen);

        return $chosen !== [] && $chosen !== ['no-cache' => true, 'private' => true];
    }

    private function variesOnEncodingOnly(Response $response): bool
    {
        foreach ($response->getVary() as $header) {
            if (strtolower(trim($header)) !== 'accept-encoding') {
                return false;
            }
        }

        return true;
    }
}
