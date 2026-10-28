<?php

declare(strict_types=1);

use Brewless\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

const EDGE_CACHE = 'max-age=0, public, s-maxage=3600';

beforeEach(function (): void {
    config(['session.driver' => 'array']);

    Route::get('/page', fn (): string => 'the same for everybody');
    Route::get('/moved', fn () => redirect('/page', 301));
    Route::get('/found', fn () => redirect('/page'));
    Route::get('/gone', fn () => response('gone', 410));
    Route::get('/broken', fn () => response('broken', 500));
    Route::get('/chosen', fn () => response('mine')->header('Cache-Control', 'no-cache'));
    Route::get('/private', fn () => response('mine')->header('Cache-Control', 'private'));
    Route::get('/timed', fn () => response('mine')->header('Cache-Control', 'public, max-age=60'));
    Route::get('/cookie', fn () => response('crumbs')->cookie('flavour', 'oat'));
    Route::get('/negotiated', fn () => response('either')->header('Vary', 'Accept'));
    Route::get('/compressed', fn () => response('either')->header('Vary', 'Accept-Encoding'));
    // A page that put something in its session keeps it (a page that did not loses it, see StatelessGuestsTest).
    Route::middleware('web')->get('/session', function (): string {
        session()->put('seen', true);

        return 'with a session';
    });
    Route::post('/form', fn (): string => 'posted');
});

test('an answer that is the same for everybody may be kept at the edge, not in the browser', function (string $path): void {
    $this->get($path)->assertHeader('Cache-Control', EDGE_CACHE);
})->with(['/page', '/moved', '/gone', '/compressed', '/nothing-here']);

test('a HEAD is answered the same way', function (): void {
    $this->call('HEAD', '/page')->assertHeader('Cache-Control', EDGE_CACHE);
});

test('a request that says who is asking is never kept', function (array $headers): void {
    $this->withHeaders($headers)->get('/page')->assertHeader('Cache-Control', 'no-cache, private');
})->with([
    'a cookie' => [['Cookie' => 'theme=dark']],
    'credentials' => [['Authorization' => 'Bearer token']],
]);

test('an answer that may differ per visitor is never kept', function (string $path): void {
    expect($this->get($path)->headers->get('Cache-Control'))->not->toContain('public');
})->with(['/found', '/broken', '/cookie', '/negotiated', '/session']);

test('a Cache-Control the application chose stays as it is', function (string $path, string $chosen): void {
    $this->get($path)->assertHeader('Cache-Control', $chosen);
})->with([
    ['/chosen', 'no-cache, private'],
    ['/private', 'private'],
    ['/timed', 'max-age=60, public'],
]);

test('only a GET or a HEAD is kept', function (): void {
    expect($this->post('/form')->headers->get('Cache-Control'))->not->toContain('public');
});

test('the routes of the package are never kept, with or without the secret', function (): void {
    expect($this->getJson('/_ops/status', $this->ops())->assertOk()->headers->get('Cache-Control'))->not->toContain('public');
    expect($this->get('/_ops/status')->assertNotFound()->headers->get('Cache-Control'))->not->toContain('public');
});

test('the seconds are the edge cache setting, and 0 keeps nothing', function (): void {
    config(['brewless.edge.cache' => 3600]);
    $this->get('/page')->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=3600');

    config(['brewless.edge.cache' => 0]);
    $this->get('/page')->assertHeader('Cache-Control', 'no-cache, private');
});
