<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PlainResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends crawlers from the addresses outside the edge to the application's
 * own domain, so they stop waking the container.
 *
 * The web container answers on more than the domain behind the edge: on its
 * own address at Scaleway (`<name>.functions.fnc.<region>.scw.cloud`) and on
 * the test address Brewless gives the environment (a name under
 * `brewless.dev`). Nothing keeps an answer there, so every request on those
 * addresses reaches the container, and a crawler that found one keeps the
 * container awake on its own: measured on coding.agency (17 November 2026),
 * crawlers asked a page there about every three minutes, and a container that
 * sleeps after fifteen quiet minutes never slept.
 *
 * On the container's own address, for an application whose edge holds a
 * secret, a GET or HEAD that did not carry that secret gets a permanent
 * redirect to the same path on the host of APP_URL, and robots.txt there
 * disallows everything. The redirect may be kept a day and says `noindex`,
 * so a search engine moves what it knows to the own domain. What Brewless
 * itself sends the container is left alone: everything under `/_ops/`, and
 * every other method, among them the `POST /` of the scheduler and the
 * queue. Without an edge secret there is no edge, and the container's own
 * address may be the only one the application has, so nothing changes.
 *
 * The test address is there for people, to try an environment before its
 * domain points at it, so they keep getting the application there, as
 * PreviewHost hands it out. Only a crawler, known by its user agent, is
 * sent to the own domain; robots.txt there already disallows everything,
 * for everybody.
 *
 * Nothing is sent anywhere when APP_URL names no host, or names the very
 * host the request came in on.
 */
final class LeaveContainerAddress
{
    /** Words in the user agent of a crawler; matched without regard to case. */
    private const string CRAWLER = '/bot|crawler|spider|GPTBot|ClaudeBot|PerplexityBot|Bytespider|CCBot|facebookexternalhit/i';

    /** How long a crawler may keep the redirect: a day. */
    private const int KEEP_REDIRECT = 86400;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $path = $request->getPathInfo();

        if ($path === '/_ops' || str_starts_with($path, '/_ops/')) {
            return $next($request);
        }

        $host = strtolower(rtrim($request->getHost(), '.'));
        $own = $this->ownHost();

        if ($own === null || $own === $host) {
            return $next($request);
        }

        if (PreviewHost::isPreview($host)) {
            return $path !== '/robots.txt' && $this->isCrawler($request)
                ? $this->redirect($request, $own)
                : $next($request);
        }

        if (TrustEdgeHost::secrets() === []
            || $request->attributes->get(TrustEdgeHost::ATTRIBUTE) === true
            || ! TrustEdgeHost::isContainerAddress($host)) {
            return $next($request);
        }

        if ($path === '/robots.txt') {
            return $this->keepOut(new PlainResponse("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }

        return $this->redirect($request, $own);
    }

    private function ownHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
    }

    private function isCrawler(Request $request): bool
    {
        return preg_match(self::CRAWLER, (string) $request->userAgent()) === 1;
    }

    /** The same path and query on the own domain, for good. */
    private function redirect(Request $request, string $own): Response
    {
        return $this->keepOut(new RedirectResponse('https://'.$own.$request->getRequestUri(), 301, [
            'Cache-Control' => 'public, max-age='.self::KEEP_REDIRECT,
        ]));
    }

    private function keepOut(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
