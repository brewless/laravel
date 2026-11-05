<?php

declare(strict_types=1);

use Brewless\Laravel\Http\Middleware\TrustEdgeHost;
use Brewless\Laravel\Tests\TestCase;

uses(TestCase::class);

test('through the edge the visitor hostname is put back, and the secret goes no further', function (): void {
    $this->get('/host', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => 'www.shop.example'])
        ->assertOk()
        ->assertSee('www.shop.example|secret-gone');

    $this->get('/host', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => 'shop.example'])->assertSee('shop.example|secret-gone');
});

test('through the edge the request is https, so generated urls are too', function (): void {
    $this->get('http://localhost/url', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => 'www.shop.example'])
        ->assertSee('https://www.shop.example/build/app.css|secure');
});

test('without the edge secret the scheme stays what the container saw', function (array $headers): void {
    $this->get('http://localhost/url', $headers)->assertSee('http://localhost/build/app.css|plain');
})->with([
    'no secret' => [['X-Forwarded-Proto' => 'https']],
    'wrong secret' => [['X-Brewless-Edge' => 'guess', 'Cdn-Host' => 'www.shop.example']],
]);

test('on the container\'s own address at Scaleway the request is https, so generated urls are too', function (?string $secret, string $host): void {
    config(['brewless.edge.secret' => $secret]);

    $this->get('http://'.$host.'/url')->assertSee('https://'.$host.'/build/app.css|secure');
})->with([
    'without an edge' => [null],
    'with an edge, called directly' => [TestCase::EDGE_SECRET],
])->with([
    'fr-par' => ['shopa1b2c3d4-web.functions.fnc.fr-par.scw.cloud'],
    'nl-ams' => ['shopa1b2c3d4-web.functions.fnc.nl-ams.scw.cloud'],
]);

test('any other host keeps the scheme the container saw, whatever X-Forwarded-Proto says', function (string $host): void {
    config(['brewless.edge.secret' => null]);

    $this->get('http://'.$host.'/url', ['X-Forwarded-Proto' => 'https'])->assertSee('http://'.$host.'/build/app.css|plain');
})->with([
    'local development' => ['shop.test'],
    'localhost' => ['localhost'],
    'the functions domain itself' => ['functions.fnc.fr-par.scw.cloud'],
    'two names deep' => ['evil.shop-web.functions.fnc.fr-par.scw.cloud'],
    'another Scaleway service' => ['shop.s3.fr-par.scw.cloud'],
    'a lookalike' => ['shop-web.functions.fnc.fr-par.scw.cloud.evil.example'],
]);

test('without the edge secret nobody chooses a hostname', function (): void {
    $this->get('/host', ['Cdn-Host' => 'www.shop.example'])->assertSee('localhost|');
    $this->get('/host', ['X-Brewless-Edge' => 'wrong', 'Cdn-Host' => 'www.shop.example'])->assertSee('localhost|secret-gone');
});

test('even the edge cannot name a hostname that is not yours', function (string $host): void {
    $this->get('/host', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => $host])->assertSee('localhost|');
})->with(['evil.example', 'shop.example.evil.example', 'notshop.example', 'shop.example:8080/x', '']);

test('an application without an edge secret believes no header', function (): void {
    config(['brewless.edge.secret' => null]);

    $this->get('/host', ['X-Brewless-Edge' => '', 'Cdn-Host' => 'www.shop.example'])->assertSee('localhost|');
});

test('through the edge the visitor address is what the edge appended, whatever the visitor put in front', function (): void {
    $edge = ['X-Brewless-Edge' => TestCase::EDGE_SECRET];

    $this->get('/address', $edge + ['X-Forwarded-For' => '203.0.113.7'])->assertSee('forwarded:203.0.113.7');
    $this->get('/address', $edge + ['X-Forwarded-For' => '198.51.100.1, 203.0.113.7'])->assertSee('forwarded:203.0.113.7');

    config(['brewless.edge.hops' => 2]);

    $this->get('/address', $edge + ['X-Forwarded-For' => '198.51.100.1, 203.0.113.7, 192.0.2.10'])->assertSee('forwarded:203.0.113.7');
    $this->get('/address', $edge + ['X-Forwarded-For' => '203.0.113.7'])->assertSee('forwarded:nothing');
});

test('without the edge secret the forwarded address is dropped, so nobody picks their own', function (array $headers): void {
    $this->get('/address', $headers)->assertSee('forwarded:nothing');
})->with([
    'no secret' => [['X-Forwarded-For' => '203.0.113.7']],
    'wrong secret' => [['X-Brewless-Edge' => 'guess', 'X-Forwarded-For' => '203.0.113.7']],
    'not an address' => [['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'X-Forwarded-For' => 'unknown']],
]);

test('an application without an edge keeps the address its provider appended, and nothing a visitor put in front', function (): void {
    config(['brewless.edge.secret' => null]);

    $this->get('/address', ['X-Forwarded-For' => '203.0.113.7'])->assertSee('forwarded:203.0.113.7');
    // A visitor who writes an address of their choosing in front gets the one the provider saw.
    $this->get('/address', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.7'])->assertSee('forwarded:203.0.113.7');
    $this->get('/address', ['X-Forwarded-For' => 'not-an-address'])->assertDontSee('not-an-address');
});

/*
 * Replacing the edge secret (BL-249). The edge and the container cannot change
 * in the same instant, so for the minutes in between the container holds two
 * secrets and answers to either. What matters here is that the second one is a
 * secret like the first — it puts the hostname back, marks the request secure
 * and lets the visitor's address through — and that holding two widens nothing
 * else.
 *
 * Every request names its host in full (`http://localhost/host`). A relative
 * path is turned into a URL with `url()`, which answers with the host of the
 * last request that was believed, so a test that asks for `/host` twice would
 * assert the second time against a host it set itself.
 */
test('while the secret is being replaced the one before it counts just as much', function (string $secret): void {
    config(['brewless.edge.secret' => 'the-new-one', 'brewless.edge.previous_secret' => TestCase::EDGE_SECRET]);

    $this->get('http://localhost/host', ['X-Brewless-Edge' => $secret, 'Cdn-Host' => 'www.shop.example'])
        ->assertOk()
        ->assertSee('www.shop.example|secret-gone');

    $this->get('http://localhost/url', ['X-Brewless-Edge' => $secret, 'Cdn-Host' => 'www.shop.example'])
        ->assertSee('https://www.shop.example/build/app.css|secure');

    $this->get('http://localhost/address', ['X-Brewless-Edge' => $secret, 'X-Forwarded-For' => '203.0.113.7'])
        ->assertSee('forwarded:203.0.113.7');
})->with([
    'the one in force' => ['the-new-one'],
    'the one before it' => [TestCase::EDGE_SECRET],
]);

test('holding two secrets widens nothing: a third guess is still nobody', function (): void {
    config(['brewless.edge.secret' => 'the-new-one', 'brewless.edge.previous_secret' => TestCase::EDGE_SECRET]);

    $this->get('http://localhost/host', ['X-Brewless-Edge' => 'guess', 'Cdn-Host' => 'www.shop.example'])->assertSee('localhost|secret-gone');
    $this->get('http://localhost/address', ['X-Brewless-Edge' => 'guess', 'X-Forwarded-For' => '203.0.113.7'])->assertSee('forwarded:nothing');
});

test('holding two secrets does not widen whose hostnames may be named', function (): void {
    config(['brewless.edge.secret' => 'the-new-one', 'brewless.edge.previous_secret' => TestCase::EDGE_SECRET]);

    $this->get('http://localhost/host', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => 'evil.example'])->assertSee('localhost|');
});

test('an empty secret is no secret, so it opens nothing', function (mixed $previous): void {
    config(['brewless.edge.secret' => 'the-new-one', 'brewless.edge.previous_secret' => $previous]);

    $this->get('http://localhost/host', ['X-Brewless-Edge' => '', 'Cdn-Host' => 'www.shop.example'])->assertSee('localhost|secret-gone');
    $this->get('http://localhost/host', ['X-Brewless-Edge' => 'the-new-one', 'Cdn-Host' => 'www.shop.example'])->assertSee('www.shop.example|secret-gone');
})->with(['empty' => [''], 'unset' => [null]]);

test('the two are equals: one before it and nothing in force is believed, and a guess still is not', function (): void {
    config(['brewless.edge.secret' => null, 'brewless.edge.previous_secret' => TestCase::EDGE_SECRET]);

    $this->get('http://localhost/host', ['X-Brewless-Edge' => 'guess', 'Cdn-Host' => 'www.shop.example'])->assertSee('localhost|secret-gone');
    $this->get('http://localhost/host', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => 'www.shop.example'])->assertSee('www.shop.example|secret-gone');
});

test('the same secret given twice is one secret, and no secret at all is none', function (): void {
    config(['brewless.edge.previous_secret' => TestCase::EDGE_SECRET]);

    expect(TrustEdgeHost::secrets())->toBe([TestCase::EDGE_SECRET]);

    config(['brewless.edge.secret' => null, 'brewless.edge.previous_secret' => null]);

    expect(TrustEdgeHost::secrets())->toBe([]);
});
