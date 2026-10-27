<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Tests\TestCase;

uses(TestCase::class);

/** Register the package again, as an application boots it with this configuration. */
function bootWith(array $config): void
{
    config($config);
    new BrewlessServiceProvider(app())->register();
}

test('the schedule runs in Amsterdam time when the application chose no zone for it', function (): void {
    bootWith(['app.schedule_timezone' => null, 'brewless.schedule_timezone' => 'Europe/Amsterdam']);

    expect(config('app.schedule_timezone'))->toBe('Europe/Amsterdam');
});

test('a zone the application chose for its schedule is kept', function (): void {
    bootWith(['app.schedule_timezone' => 'America/New_York', 'brewless.schedule_timezone' => 'Europe/Amsterdam']);

    expect(config('app.schedule_timezone'))->toBe('America/New_York');
});

test('SCHEDULE_TIMEZONE chooses another zone, such as UTC', function (): void {
    bootWith(['app.schedule_timezone' => null, 'brewless.schedule_timezone' => 'UTC']);

    expect(config('app.schedule_timezone'))->toBe('UTC');
});

test('the package reads SCHEDULE_TIMEZONE, and Amsterdam without it', function (): void {
    $config = (static fn (): array => require __DIR__.'/../../config/brewless.php')();

    expect($config['schedule_timezone'])->toBe(env('SCHEDULE_TIMEZONE', 'Europe/Amsterdam'))
        ->and((string) file_get_contents(__DIR__.'/../../config/brewless.php'))->toContain("env('SCHEDULE_TIMEZONE', 'Europe/Amsterdam')");
});
