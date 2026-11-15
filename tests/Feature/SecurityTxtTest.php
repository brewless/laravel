<?php

declare(strict_types=1);

use Brewless\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

test('without a contact there is no security.txt: a file without one is not valid', function (): void {
    $this->get('/.well-known/security.txt')->assertNotFound();
});

test('with a contact the package answers security.txt, expiring a year from the start of today', function (): void {
    $this->travelTo('2026-11-15 14:30:00');
    config(['brewless.security.contact' => 'security@shop.example, https://shop.example/report']);

    $response = $this->get('/.well-known/security.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and($response->getContent())->toBe(implode("\n", [
            'Contact: mailto:security@shop.example',
            'Contact: https://shop.example/report',
            'Expires: 2027-11-15T00:00:00Z',
            'Preferred-Languages: en',
            'Canonical: http://localhost/.well-known/security.txt',
        ])."\n")
        // No session and no cookie: the edge may keep it.
        ->and($response->headers->getCookies())->toBe([]);
});

test('a policy is named when it is set', function (): void {
    config(['brewless.security.contact' => 'mailto:security@shop.example', 'brewless.security.policy' => 'https://shop.example/disclosure']);

    $this->get('/.well-known/security.txt')->assertOk()
        ->assertSee("Contact: mailto:security@shop.example\n", false)
        ->assertSee("Policy: https://shop.example/disclosure\n", false);
});

test('a security.txt route of the application comes before the package', function (): void {
    config(['brewless.security.contact' => 'security@shop.example']);
    Route::get('/.well-known/security.txt', fn (): string => 'Contact: mailto:own@shop.example');
    app('router')->getRoutes()->refreshNameLookups();

    $this->get('/.well-known/security.txt')->assertOk()->assertSee('own@shop.example');
});
