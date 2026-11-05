<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's StartSession, which keeps a session only when the page needs one.
 *
 * Every route in the `web` group starts a session, stores it and sets its
 * cookie and the XSRF-TOKEN cookie on the answer. An answer with a cookie can
 * never be kept at the edge, so a public page wakes the container (and, with
 * sessions in the database, the database) on every visit, crawlers included.
 *
 * So a GET or HEAD from a guest without any cookie and without
 * `Authorization` gets an answer without those cookies, and nothing is
 * stored, when the session is no use to the next request:
 *
 * - the page put nothing in it but what Laravel puts there by itself (the
 *   CSRF token, the previous URL and route, an empty flash bag);
 * - the answer does not carry the CSRF token, so there is no form to post.
 *
 * Such a guest's session is not read from the store either, and garbage is
 * collected only when a session is kept: with sessions in a database, a
 * guest on a public page does not wake it.
 *
 * - the answer is no empty answer or JSON: those (Sanctum's
 *   `/sanctum/csrf-cookie`, for one) are asked for to get those cookies. An
 *   HTML page goes without them, and so does a text file such as llms.txt,
 *   a sitemap or a page as markdown.
 *
 * Anything else keeps its session exactly as before: a form, a page that put
 * something in the session (an intended URL, a flash message), a streamed
 * answer, and every visitor who sent a cookie, signed in or not.
 *
 * A page whose script posts later without the token in its HTML (Inertia, a
 * login screen of a Vue app) relies on the XSRF-TOKEN cookie. Laravel 13's
 * CSRF check lets a same-origin request through without a token, Laravel
 * 12's does not, so `brewless.edge.stateless_guests`
 * (`BREWLESS_STATELESS_GUESTS`) is on by itself only where
 * PreventRequestForgery exists. `true` turns it on on Laravel 12 too, `false`
 * turns it off.
 */
final class StartSessionWhenNeeded extends StartSession
{
    /** The request attribute that says the session of this request was left unstored on purpose. */
    public const string SKIPPED = 'brewless.session_skipped';

    /** What Laravel puts in a fresh session by itself. */
    private const array OWN_KEYS = ['_token', '_previous', '_flash'];

    /**
     * @param  Session  $session
     */
    #[\Override]
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        $request->setLaravelSession($this->startSession($request, $session));

        $response = $next($request);

        $this->storeCurrentUrl($request, $session);

        if ($response instanceof Response && $this->unneeded($request, $session, $response)) {
            $this->dropCookies($response, $session);
            $request->attributes->set(self::SKIPPED, true);

            return $response;
        }

        // Laravel collects garbage before the page; here it waits until the session is kept, so a guest
        // whose session is left out never touches the session store at all.
        $this->collectGarbage($session);
        $this->addCookieToResponse($response, $session);
        $this->saveSession($request);

        return $response;
    }

    /**
     * Without a session cookie the session gets a new, random id, and reading that from the store finds
     * nothing: it is started without the read, so a guest does not wake a database that holds the sessions.
     * Kept, it is written as a new session, as it would have been.
     *
     * @param  Session  $session
     */
    #[\Override]
    protected function startSession(Request $request, $session)
    {
        if (! self::enabled() || $request->cookies->has($session->getName()) || ! $session instanceof Store) {
            return parent::startSession($request, $session);
        }

        $store = $session->getHandler();
        $session->setHandler(new NullSessionHandler);

        try {
            return parent::startSession($request, $session);
        } finally {
            $session->setHandler($store);
        }
    }

    private function unneeded(Request $request, Session $session, Response $response): bool
    {
        if (! self::enabled() || $this->askedForCookies($request, $response)) {
            return false;
        }

        if ((! $request->isMethod('GET') && ! $request->isMethod('HEAD')) || $request->headers->has('Cookie') || $request->cookies->count() > 0 || $request->headers->has('Authorization')) {
            return false;
        }

        if (array_diff(array_keys($session->all()), self::OWN_KEYS) !== [] || $this->flashes($session)) {
            return false;
        }

        $content = $this->readable($response);
        $token = $session->token();

        // An answer that cannot be read back keeps its session.
        return $content !== null && (! is_string($token) || $token === '' || ! str_contains($content, $token));
    }

    /**
     * The body as the visitor reads it, or null when it cannot be read: a
     * streamed answer, or one in an encoding this cannot undo.
     *
     * Middleware the application appends to its `web` group runs deeper than
     * this one, so its work on the body is already done by the time this looks.
     * A page whose body was compressed there holds its token just the same,
     * only not findable, and dropping the session on that is dropping it for
     * the wrong reason: the page then goes to the edge with a token that
     * belongs to a session nobody kept (coding.agency, 5 November 2026).
     */
    private function readable(Response $response): ?string
    {
        $content = $response->getContent();

        if (! is_string($content)) {
            return null;
        }

        $encoding = strtolower(trim((string) $response->headers->get('Content-Encoding')));

        if ($encoding === '' || $encoding === 'identity') {
            return $content;
        }

        // A body that is not what it says it is only makes these warn; unreadable is the answer either way.
        $plain = match ($encoding) {
            'gzip', 'x-gzip' => @gzdecode($content),
            'deflate' => @gzuncompress($content),
            'br' => function_exists('brotli_uncompress') ? @brotli_uncompress($content) : false,
            default => false,
        };

        return is_string($plain) ? $plain : null;
    }

    /**
     * Whether the answer is what a script asks for to get the session and
     * XSRF-TOKEN cookies: an empty answer or JSON (Sanctum's
     * `/sanctum/csrf-cookie`, for one). Any other answer, an HTML page or a
     * text file such as llms.txt, a sitemap or a page as markdown, is read
     * for its content: with a cookie the edge never keeps it, and every
     * visit wakes the container (seen on coding.agency, 3 November 2026).
     */
    private function askedForCookies(Request $request, Response $response): bool
    {
        $type = strtolower((string) $response->headers->get('Content-Type'));

        // A HEAD has no body by nature: only its type says what it is.
        $empty = $response->getStatusCode() === 204 || ($response->getContent() === '' && ! $request->isMethod('HEAD'));

        return $empty
            || str_starts_with($type, 'application/json')
            || preg_match('#^application/[^;]+\+json#', $type) === 1;
    }

    /**
     * Whether guests go without a session: as `brewless.edge.stateless_guests` says, or, when it says
     * nothing, where the CSRF check lets a same-origin request through without a token (Laravel 13).
     */
    public static function enabled(): bool
    {
        $setting = config('brewless.edge.stateless_guests');

        return is_bool($setting) ? $setting : class_exists('Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery');
    }

    /** Whether the flash bag holds anything, now or for the next request. */
    private function flashes(Session $session): bool
    {
        $flash = $session->get('_flash', []);

        return is_array($flash) && (($flash['old'] ?? []) !== [] || ($flash['new'] ?? []) !== []);
    }

    /** The session cookie and XSRF-TOKEN come off the answer, whoever set them. */
    private function dropCookies(Response $response, Session $session): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), [$session->getName(), 'XSRF-TOKEN'], true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }
}
