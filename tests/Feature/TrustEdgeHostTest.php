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
