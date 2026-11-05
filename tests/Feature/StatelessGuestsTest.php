<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Http\Middleware\StartSessionWhenNeeded;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

uses(TestCase::class);

const GUEST_PAGE_CACHE = 'max-age=0, public, s-maxage=3600';

beforeEach(function (): void {
    if (! Schema::hasTable('sessions')) {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    // On, as on Laravel 13 by itself; Laravel 12 needs it said (the test of the unset setting says null).
    config(['session.driver' => 'database', 'session.cookie' => 'shop_session', 'session.lottery' => [100, 100], 'brewless.edge.stateless_guests' => true]);

    Route::middleware('web')->group(function (): void {
        Route::get('/pricing', fn (): string => 'the same for everybody');
        Route::get('/contact', fn (): string => '<form method="post"><input type="hidden" name="_token" value="'.csrf_token().'"></form>');
        Route::get('/remember', function (): string {
            session()->put('seen', true);

            return 'remembered';
        });
        Route::get('/send', fn () => redirect('/pricing')->with('status', 'sent'));
        Route::post('/contact', fn (): string => 'posted');
    });

    Route::middleware('web')->group(function (): void {
        Route::withoutSession(function (): void {
            Route::get('/docs', fn (): string => 'docs');
        });
        Route::get('/about', fn (): string => 'about')->withoutSession();
    });
});

/**
 * The names of the cookies an answer sets.
 *
 * @return list<string>
 */
function cookieNames(TestResponse $response): array
{
    return array_map(static fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());
}

/**
 * Every query against the sessions table while the callback runs.
 *
 * @return list<string>
 */
function sessionQueries(Closure $callback): array
{
    $queries = [];
    Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'sessions')) {
            $queries[] = $query->sql;
        }
    });

    $callback();

    return $queries;
}

test('a guest without a cookie on a page without a form gets no session, so the edge may keep the page', function (): void {
    $queries = sessionQueries(function (): void {
        $response = $this->get('/pricing')->assertOk();

        expect(cookieNames($response))->toBe([])
            ->and($response->headers->get('Cache-Control'))->toBe(GUEST_PAGE_CACHE);
    });

    // Not read, not written, no garbage collected: the database that holds the sessions may sleep.
    expect($queries)->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);
});

test('a HEAD is answered the same way', function (): void {
    $response = $this->call('HEAD', '/pricing')->assertOk();

    expect(cookieNames($response))->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);
});

test('a page that needs its session keeps it, exactly as Laravel does', function (string $path): void {
    $response = $this->get($path);

    expect(cookieNames($response))->toContain('shop_session')
        ->and(cookieNames($response))->toContain('XSRF-TOKEN')
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public')
        ->and(DB::table('sessions')->count())->toBe(1);
})->with([
    'a form with the CSRF token' => '/contact',
    'something put in the session' => '/remember',
    'a flash message for the next page' => '/send',
]);

test('a visitor who sent a cookie keeps the session, whatever the page', function (array $cookies): void {
    $response = $this->withHeaders(['Cookie' => 'theme=dark'])->withCookies($cookies)->get('/pricing')->assertOk();

    expect(cookieNames($response))->toContain('shop_session')
        ->and(DB::table('sessions')->count())->toBe(1);
})->with([
    'another cookie' => [['theme' => 'dark']],
    'the session cookie' => [['shop_session' => str_repeat('a', 40)]],
]);

test('credentials keep the session', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer token'])->get('/pricing')->assertOk();

    expect(cookieNames($response))->toContain('shop_session');
});

test('a POST keeps the session', function (): void {
    $response = $this->post('/contact')->assertOk();

    expect(cookieNames($response))->toContain('shop_session')
        ->and(DB::table('sessions')->count())->toBe(1);
});

test('with stateless guests turned off every page of the web group has a session again', function (): void {
    config(['brewless.edge.stateless_guests' => false]);

    $response = $this->get('/pricing')->assertOk();

    expect(cookieNames($response))->toContain('shop_session')
        ->and(cookieNames($response))->toContain('XSRF-TOKEN')
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public')
        ->and(DB::table('sessions')->count())->toBe(1);
});

test('a route without a session has none, not even for a visitor with a cookie', function (string $path): void {
    $guest = $this->get($path)->assertOk();
    $visitor = $this->withCookies(['shop_session' => str_repeat('a', 40)])->get($path)->assertOk();

    expect(cookieNames($guest))->toBe([])
        ->and($guest->headers->get('Cache-Control'))->toBe(GUEST_PAGE_CACHE)
        ->and(cookieNames($visitor))->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);
})->with(['a group' => '/docs', 'one route' => '/about']);

test('a route without a session goes without the session, its errors and the CSRF check of Laravel 12 and 13', function (): void {
    $middleware = BrewlessServiceProvider::sessionMiddleware();

    expect($middleware)->toContain(StartSession::class)
        ->and($middleware)->toContain(ShareErrorsFromSession::class);

    foreach (['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'] as $csrf) {
        expect(in_array($csrf, $middleware, true))->toBe(class_exists($csrf), $csrf);
    }
});

test('a kept session still collects its garbage', function (): void {
    // The lottery always hits in these tests: a kept session cleans up as Laravel would.
    $queries = sessionQueries(fn () => $this->get('/remember')->assertOk());

    expect(implode("\n", $queries))->toContain('delete');
});

test('an empty answer or JSON keeps its cookies: a script asks for them (Sanctum\'s csrf-cookie)', function (): void {
    Route::middleware('web')->get('/csrf-cookie', fn () => response()->noContent());
    Route::middleware('web')->get('/json', fn () => response()->json(['ok' => true]));

    foreach (['/csrf-cookie', '/json'] as $path) {
        $names = array_map(static fn ($cookie): string => $cookie->getName(), $this->get($path)->headers->getCookies());

        expect($names)->toContain('shop_session');
    }
});

test('a text answer that is not HTML goes without a session too, so the edge may keep it (llms.txt, a sitemap, markdown)', function (string $type): void {
    Route::middleware('web')->get('/plain', fn () => response('# Shop', 200, ['Content-Type' => $type]));

    $response = $this->get('/plain');

    expect(cookieNames($response))->toBe([]);
})->with(['text/plain; charset=utf-8', 'application/xml', 'text/markdown; charset=utf-8', 'text/css']);

test('a text answer that carries the CSRF token keeps its session', function (): void {
    Route::middleware('web')->get('/token.txt', fn () => response('token: '.csrf_token(), 200, ['Content-Type' => 'text/plain']));

    expect(cookieNames($this->get('/token.txt')))->toContain('shop_session', 'XSRF-TOKEN');
});

test('unset, it follows the CSRF check: on where a same-origin request needs no token', function (): void {
    config(['brewless.edge.stateless_guests' => null]);

    expect(StartSessionWhenNeeded::enabled())
        ->toBe(class_exists('Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery'));

    config(['brewless.edge.stateless_guests' => true]);
    expect(StartSessionWhenNeeded::enabled())->toBeTrue();
});

/*
 | A body another middleware already worked on (BL-254).
 |
 | Middleware an application appends to its `web` group runs deeper than
 | StartSessionWhenNeeded, so compression is done before this one looks. On
 | coding.agency that hid the CSRF token from the check, and every page went to
 | the edge with a token belonging to a session nobody kept.
 */

test('a page that shows its token keeps its session, also when the body was compressed first', function (string $middleware): void {
    Route::middleware(['web', $middleware])->get('/compressed-form', fn (): string => '<form><input name="_token" value="'.csrf_token().'"></form>');

    $answer = $this->get('/compressed-form');

    expect($answer->headers->getCookies())->not->toBeEmpty()
        ->and($answer->headers->get('Cache-Control'))->not->toBe(GUEST_PAGE_CACHE);
})->with([Gzips::class, Deflates::class]);

test('a compressed page that shows no token still loses its session', function (): void {
    Route::middleware(['web', Gzips::class])->get('/compressed-page', fn (): string => 'the same for everybody');

    $this->get('/compressed-page')->assertHeader('Cache-Control', GUEST_PAGE_CACHE);
});

test('an encoding that cannot be undone keeps the session, rather than guessing', function (): void {
    Route::middleware(['web', SaysSomethingElse::class])->get('/unknown-encoding', fn (): string => 'the same for everybody');

    $answer = $this->get('/unknown-encoding');

    expect($answer->headers->getCookies())->not->toBeEmpty()
        ->and($answer->headers->get('Cache-Control'))->not->toBe(GUEST_PAGE_CACHE);
});

/** Compression of an application's own, appended to its web group and so deeper than the session middleware. */
final class Gzips
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->setContent((string) gzencode((string) $response->getContent()));
        $response->headers->set('Content-Encoding', 'gzip');

        return $response;
    }
}

final class Deflates
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->setContent((string) gzcompress((string) $response->getContent()));
        $response->headers->set('Content-Encoding', 'deflate');

        return $response;
    }
}

final class SaysSomethingElse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Encoding', 'something-else');

        return $response;
    }
}
