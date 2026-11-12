<?php

declare(strict_types=1);

use Brewless\Laravel\Realtime\AllowedOrigins;
use Brewless\Laravel\Tests\TestCase;

uses(TestCase::class);

/** Reverb's configuration as `install:broadcasting` leaves it: every origin may connect. */
function reverbApp(array $app = []): array
{
    return ['reverb.apps.apps' => [['key' => 'k', 'secret' => 's', 'app_id' => '1', 'allowed_origins' => ['*'], ...$app]]];
}

test('the hostnames Brewless names are the only ones that may connect to reverb', function (): void {
    config([...reverbApp(), 'brewless.realtime.allowed_origins' => 'shop.example.eu, *.shop.example.eu']);

    AllowedOrigins::limit();

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['shop.example.eu', '*.shop.example.eu']);
});

test('a url or a port in the list is reduced to its host', function (): void {
    config([...reverbApp(), 'brewless.realtime.allowed_origins' => 'https://Shop.example.eu:8443,shop.example.eu']);

    AllowedOrigins::limit();

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['shop.example.eu']);
});

test('a list the application wrote itself is left alone', function (): void {
    config([...reverbApp(['allowed_origins' => ['app.example.eu']]), 'brewless.realtime.allowed_origins' => 'shop.example.eu']);

    AllowedOrigins::limit();

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['app.example.eu']);
});

test('every origin stays allowed when the variable says so, is empty or is not there', function (mixed $origins): void {
    config([...reverbApp(), 'brewless.realtime.allowed_origins' => $origins]);

    AllowedOrigins::limit();

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);
})->with(['a star' => '*', 'a star among names' => 'shop.example.eu,*', 'empty' => '', 'not set' => null]);

test('an application without reverb is left as it is', function (): void {
    config(['reverb' => null, 'brewless.realtime.allowed_origins' => 'shop.example.eu']);

    AllowedOrigins::limit();

    expect(config('reverb'))->toBeNull();
});

test('the package reads REVERB_ALLOWED_ORIGINS and limits the origins before the application boots', function (): void {
    expect((string) file_get_contents(__DIR__.'/../../config/brewless.php'))->toContain("env('REVERB_ALLOWED_ORIGINS')")
        ->and((string) file_get_contents(__DIR__.'/../../src/BrewlessServiceProvider.php'))->toContain('$this->app->booting(AllowedOrigins::limit(...));');
});
