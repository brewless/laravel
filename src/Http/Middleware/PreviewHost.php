<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PlainResponse;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the application answer on the test address Brewless gives an
 * environment, and keeps that address out of search engines.
 *
 * The test address is a name under Brewless's test domain that leads
 * straight to the web container. An application that ties its routes to its
 * own domain would answer it with a 404, so the request is routed as one for
 * the host of APP_URL, while the URLs the application generates keep the
 * test address: its pages load their assets from where they came from, as a
 * strict Content-Security-Policy demands. Every answer carries `X-Robots-Tag: noindex,
 * nofollow`, and robots.txt disallows everything, whatever the application
 * serves there itself.
 *
 * On the test address the session cookie is a `__Host-` cookie: Secure, for
 * the whole path and for this exact name only. Every environment of every
 * customer gets a test address under the same domain, and a browser never
 * lets one of those set or overwrite a `__Host-` cookie of another, so an
 * application on a neighbouring test address cannot put its own session on
 * this one.
 *
 * Only a name under one of the test domains counts. Scaleway sends a request
 * to a container only for names attached to it, so nobody reaches this
 * container with a test address Brewless did not give it.
 */
final class PreviewHost
{
    /** Set on a request that came in on the test address. */
    public const string ATTRIBUTE = 'brewless.preview';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPreview($request->getHost())) {
            return $next($request);
        }

        $request->attributes->set(self::ATTRIBUTE, true);
        $session = $this->hostOnlySession();

        if ($request->isMethod('GET') && $request->getPathInfo() === '/robots.txt') {
            return $this->keepOut(new PlainResponse("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }

        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($own) || $own === '') {
            try {
                return $this->keepOut($next($request));
            } finally {
                $this->restoreSession($session);
            }
        }

        $preview = $request->getScheme().'://'.strtolower(rtrim($request->getHost(), '.'));

        $request->headers->set('Host', $own);
        $request->server->set('HTTP_HOST', $own);
        $request->server->set('SERVER_NAME', $own);

        // Routing sees the own domain; the addresses the application writes
        // into its pages (assets, links, redirects) stay on the test address,
        // so the browser loads them from the same origin as the page.
        URL::forceRootUrl($preview);

        try {
            return $this->keepOut($next($request));
        } finally {
            // A process that serves more than one request must not carry it to the next.
            URL::forceRootUrl(null);
            $this->restoreSession($session);
        }
    }

    /**
     * Make the session cookie a `__Host-` cookie for this request, and return
     * the settings as they were, to put back after it.
     *
     * @return array<string, mixed>
     */
    private function hostOnlySession(): array
    {
        $before = [
            'session.cookie' => config('session.cookie'),
            'session.domain' => config('session.domain'),
            'session.secure' => config('session.secure'),
            'session.path' => config('session.path'),
        ];
        $name = is_string($before['session.cookie']) && $before['session.cookie'] !== '' ? $before['session.cookie'] : 'laravel_session';

        $hostOnly = str_starts_with($name, '__Host-') ? $name : '__Host-'.$name;

        config([
            'session.cookie' => $hostOnly,
            'session.domain' => null,
            'session.secure' => true,
            'session.path' => '/',
        ]);

        // A store made before this request keeps the name it was made with.
        if (app()->resolved('session')) {
            app('session')->driver()->setName($hostOnly);
        }

        return $before;
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function restoreSession(array $before): void
    {
        config($before);

        if (app()->resolved('session') && is_string($before['session.cookie']) && $before['session.cookie'] !== '') {
            app('session')->driver()->setName($before['session.cookie']);
        }
    }

    private function isPreview(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        foreach ((array) config('brewless.preview.domains') as $domain) {
            $domain = is_string($domain) ? strtolower(trim($domain, '. ')) : '';

            if ($domain !== '' && str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function keepOut(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
