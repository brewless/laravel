<?php

declare(strict_types=1);

use Brewless\Laravel\Tests\TestCase;

uses(TestCase::class);

test('through the edge the visitor hostname is put back, and the secret goes no further', function (): void {
    $this->get('/host', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => 'www.shop.example'])
        ->assertOk()
        ->assertSee('www.shop.example|secret-gone');

    $this->get('/host', ['X-Brewless-Edge' => TestCase::EDGE_SECRET, 'Cdn-Host' => 'shop.example'])->assertSee('shop.example|secret-gone');
});

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

test('an application without an edge keeps the forwarded address it was given', function (): void {
    config(['brewless.edge.secret' => null]);

    $this->get('/address', ['X-Forwarded-For' => '203.0.113.7'])->assertSee('forwarded:203.0.113.7');
});
