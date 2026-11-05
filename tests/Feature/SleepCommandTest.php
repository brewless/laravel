<?php

declare(strict_types=1);

use Brewless\Laravel\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

/**
 * What the command printed, and what it answered. Read from Artisan itself:
 * the lines are the point of this command, and they are long.
 *
 * @param  list<string>  $paths
 * @return array{0: string, 1: int}
 */
function sleepRun(array $paths = [], bool $strict = false): array
{
    $code = Artisan::call('brewless:sleep', array_filter(['--path' => $paths, '--strict' => $strict]));

    return [Artisan::output(), $code];
}

beforeEach(function (): void {
    config(['session.driver' => 'array']);

    Route::get('/page', fn (): string => 'the same for everybody');
    Route::get('/chosen', fn () => response('mine')->header('Cache-Control', 'public, max-age=60'));
    Route::get('/cookie', fn () => response('crumbs')->cookie('flavour', 'oat'));
    Route::get('/elsewhere', fn () => redirect('/page'));
    Route::middleware('web')->get('/remembers', function (): string {
        session()->put('seen', true);

        return 'with a session';
    });
    Route::get('/logo', fn () => response('<html><body><img src="/img/logo.png"><img src="/img/hero.webp?v=2903919055"></body></html>')->header('Content-Type', 'text/html'));
});

test('it says a page is kept at the edge', function (): void {
    [$output, $code] = sleepRun(['/page']);

    expect($output)->toContain('1 of 1 pages')
        ->and($output)->toContain('Nothing here wakes the container')
        ->and($code)->toBe(0);
});

test('it names the page and the rule it failed', function (string $path, string $reason): void {
    [$output] = sleepRun([$path]);

    expect($output)->toContain($path)->and($output)->toContain($reason);
})->with([
    'its own Cache-Control' => ['/chosen', 'it sets Cache-Control itself'],
    'a cookie' => ['/cookie', 'it sets a cookie'],
    // A 404 is kept on purpose: the edge answers it instead of waking the container for the next crawler.
    'a status the edge does not keep' => ['/elsewhere', 'the answer is a 302'],
]);

test('a page that keeps a session is named with the rule, not just the cookie', function (): void {
    [$output] = sleepRun(['/remembers']);

    expect($output)->toContain('/remembers')
        ->and($output)->toContain('it keeps a session')
        ->and($output)->toContain('shows a CSRF token');
});

test('it says which environment answered, because only production counts', function (): void {
    [$output] = sleepRun(['/page']);

    expect($output)->toContain('Asked in')
        ->and($output)->toContain('This is not production')
        ->and($output)->toContain('brewless command production');
});

test('--strict fails when a page cannot be kept', function (): void {
    expect(sleepRun(['/chosen'], strict: true)[1])->toBe(1)
        ->and(sleepRun(['/page'], strict: true)[1])->toBe(0);
});

test('an edge that keeps nothing is the first thing it says', function (): void {
    config(['brewless.edge.cache' => 0]);

    [$output, $code] = sleepRun(['/page']);

    expect($output)->toContain('edge.cache is 0')->and($code)->toBe(1);
});

test('it counts the files a page names that have no version', function (): void {
    [$output] = sleepRun(['/logo']);

    expect($output)->toContain('1 files your pages name have no ?v=')
        ->and($output)->toContain('/img/logo.png')
        // The one with ?v= comes from the storage zone.
        ->and($output)->not->toContain('/img/hero.webp');
});

test('it names a schedule that repeats within the hour, and leaves an hourly one alone', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->everyFiveMinutes();
    $schedule->command('queue:prune-batches')->hourly();

    [$output] = sleepRun(['/page']);

    expect($output)->toContain('Every 5 minutes')
        ->and($output)->toContain('inspire')
        ->and($output)->not->toContain('queue:prune-batches');
});

test('a page behind a sign-in and one that needs a parameter are not asked', function (): void {
    Route::middleware('auth')->get('/account', fn (): string => 'yours');
    Route::get('/post/{slug}', fn (string $slug): string => $slug);

    [$output] = sleepRun();

    expect($output)->not->toContain('/account')->and($output)->not->toContain('/post/');
});
