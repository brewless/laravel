<?php

declare(strict_types=1);

use Brewless\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

const CONTAINER_URL = 'http://shopproductionabc123-web.functions.fnc.nl-ams.scw.cloud';

const TEST_ADDRESS_URL = 'http://acme-shop-production.brewless.dev';

const CRAWLERS = [
    'GPTBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'ClaudeBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
    'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Bytespider' => 'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)',
    'facebookexternalhit' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
];

const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

beforeEach(function (): void {
    config(['app.url' => 'https://shop.example']);

    Route::get('/some/page', fn (): string => 'page');
    Route::post('/contact', fn (): string => 'contact sent');
    Route::get('/robots.txt', fn (): string => "User-agent: *\nAllow: /\n");
});

test('robots.txt on the container address disallows everything', function (): void {
    $this->get(CONTAINER_URL.'/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeText('Disallow: /');
});

test('a page on the container address sends the visitor to the own domain, path and query kept', function (string $method): void {
    $response = $this->call($method, CONTAINER_URL.'/some/page?x=1');

    $response->assertStatus(301)
        ->assertHeader('Location', 'https://shop.example/some/page?x=1')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=86400');
})->with(['GET', 'HEAD']);

test('what Brewless and forms send the container is left alone', function (): void {
    $this->post(CONTAINER_URL.'/contact')->assertOk()->assertSee('contact sent');
    $this->post(CONTAINER_URL.'/')->assertHeaderMissing('Location')->assertHeaderMissing('X-Robots-Tag');
    $this->get(CONTAINER_URL.'/_ops/status')->assertHeaderMissing('Location')->assertHeaderMissing('X-Robots-Tag');
    $this->get(CONTAINER_URL.'/_ops/status', $this->ops())->assertOk();
});

test('a request through the edge is left alone, with the secret in force or the one before it', function (string $current, ?string $previous): void {
    config(['brewless.edge.secret' => $current, 'brewless.edge.previous_secret' => $previous]);

    $this->get(CONTAINER_URL.'/some/page', ['X-Brewless-Edge' => TestCase::EDGE_SECRET])->assertOk()->assertSee('page');
    $this->get(CONTAINER_URL.'/robots.txt', ['X-Brewless-Edge' => TestCase::EDGE_SECRET])->assertSeeText('Allow: /');
})->with([
    'the one in force' => [TestCase::EDGE_SECRET, null],
    'the one before it' => ['the-new-one', TestCase::EDGE_SECRET],
]);

test('a wrong secret counts as no secret', function (): void {
    $this->get(CONTAINER_URL.'/some/page', ['X-Brewless-Edge' => 'guessed'])->assertStatus(301);
});

test('nothing changes where there is no own domain to send anybody to, or no edge', function (array $config): void {
    config($config);

    $this->get(CONTAINER_URL.'/some/page')->assertOk()->assertSee('page')->assertHeaderMissing('X-Robots-Tag');
    $this->get(CONTAINER_URL.'/robots.txt')->assertSeeText('Allow: /');
})->with([
    'APP_URL is the container address' => [['app.url' => CONTAINER_URL]],
    'APP_URL is empty' => [['app.url' => '']],
    'no edge secret' => [['brewless.edge.secret' => null, 'brewless.edge.previous_secret' => null]],
]);

test('other hosts are left alone', function (string $url): void {
    $this->get($url.'/some/page')->assertOk()->assertSee('page');
})->with(['http://shop.example', 'http://localhost', 'http://shop.scw.cloud.evil.example']);

test('a crawler on the test address is sent to the own domain', function (string $agent): void {
    $this->get(TEST_ADDRESS_URL.'/some/page?x=1', ['User-Agent' => $agent])
        ->assertStatus(301)
        ->assertHeader('Location', 'https://shop.example/some/page?x=1')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with(CRAWLERS);

test('a person on the test address gets the application as before', function (): void {
    Route::domain('shop.example')->get('/bound', fn (): string => 'bound:'.request()->getHost());

    $this->get(TEST_ADDRESS_URL.'/bound', ['User-Agent' => BROWSER])
        ->assertOk()
        ->assertSee('bound:shop.example')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('robots.txt on the test address still disallows everything, for a crawler too', function (): void {
    $this->get(TEST_ADDRESS_URL.'/robots.txt', ['User-Agent' => CRAWLERS['GPTBot']])
        ->assertOk()
        ->assertSeeText('Disallow: /');
});

test('Brewless traffic on the test address is not sent away, whatever its user agent', function (): void {
    $this->get(TEST_ADDRESS_URL.'/_ops/status', ['User-Agent' => CRAWLERS['GPTBot']])->assertHeaderMissing('Location');
    $this->post(TEST_ADDRESS_URL.'/contact', [], ['User-Agent' => CRAWLERS['GPTBot']])->assertOk();
});
