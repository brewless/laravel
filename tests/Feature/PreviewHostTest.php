<?php

declare(strict_types=1);

use Brewless\Laravel\Http\Middleware\PreviewHost;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

const PREVIEW_URL = 'http://acme-shop-production.brewless.dev';

beforeEach(function (): void {
    config(['app.url' => 'https://shop.example']);
});

test('on the test address the application answers as on its own domain, and says so to nobody but itself', function (): void {
    Route::domain('shop.example')->get('/bound', fn (): string => 'bound:'.request()->getHost().':'.(request()->attributes->get(PreviewHost::ATTRIBUTE) ? 'preview' : 'own'));

    $this->get(PREVIEW_URL.'/bound')->assertOk()->assertSee('bound:shop.example:preview');
});

test('every answer on the test address keeps search engines away, an error too', function (): void {
    $this->get(PREVIEW_URL.'/host')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get(PREVIEW_URL.'/nothing-here')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('robots.txt on the test address disallows everything, whatever the application serves there', function (): void {
    Route::get('/robots.txt', fn (): string => "User-agent: *\nAllow: /\n");

    $this->get(PREVIEW_URL.'/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeText('Disallow: /');

    $this->get('http://shop.example/robots.txt')->assertSeeText('Allow: /');
});

test('any other host is left as it is', function (string $url): void {
    $response = $this->get($url.'/host');

    $response->assertOk()->assertHeaderMissing('X-Robots-Tag');
    expect($response->getContent())->not->toStartWith('shop.example|');
})->with(['http://localhost', 'http://brewless.dev', 'http://shop.brewless.dev.evil.example', 'http://notbrewless.dev']);

test('without test domains nothing counts as a test address', function (): void {
    config(['brewless.preview.domains' => []]);

    $this->get(PREVIEW_URL.'/host')->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertSee('acme-shop-production.brewless.dev|');
});

test('an application without APP_URL keeps the host, and still stays out of search engines', function (): void {
    config(['app.url' => '']);

    $this->get(PREVIEW_URL.'/host')->assertSee('acme-shop-production.brewless.dev|')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('the addresses the application writes stay on the test address, so its assets load from the same origin', function (): void {
    Route::domain('shop.example')->get('/links', fn (): string => url('/build/app.css').'|'.asset('logo.svg'));

    $this->get(PREVIEW_URL.'/links')->assertSee('http://acme-shop-production.brewless.dev/build/app.css|http://acme-shop-production.brewless.dev/logo.svg', false);
    $this->get('https://acme-shop-production.brewless.dev/links')->assertSee('https://acme-shop-production.brewless.dev/build/app.css', false);

    // The next request, on the own domain, gets its own addresses again.
    Route::get('/plain-link', fn (): string => url('/x'));
    $this->get('http://shop.example/plain-link')->assertSee('http://shop.example/x', false);
});
