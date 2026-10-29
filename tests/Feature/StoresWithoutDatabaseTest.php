<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Tests\TestCase;

uses(TestCase::class);

// The TestCase migrates on the default connection and rolls back when the test
// ends: these tests point that name elsewhere, so it goes back before teardown.
afterEach(function (): void {
    config(['database.default' => 'testing']);
});

/*
 * The framework keeps the cache and the session in a database by default, and
 * falls back to a SQLite file in the project that a container does not carry.
 * Where no database is configured both fail on every request, so the cache
 * goes on the container's own disk and the session into the cookie.
 */

function withoutADatabase(): void
{
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => '/nowhere/database.sqlite'],
    ]);
}

test('without a database the cache goes on the disk and the session into the cookie', function (): void {
    withoutADatabase();
    config(['cache.default' => 'database', 'session.driver' => 'database']);

    (new BrewlessServiceProvider(app()))->register();

    expect(config('cache.default'))->toBe('file')
        ->and(config('session.driver'))->toBe('cookie');
});

test('a database to reach leaves both on the database', function (array $connection): void {
    config([
        'database.default' => 'main',
        'database.connections.main' => $connection,
        'cache.default' => 'database',
        'session.driver' => 'database',
    ]);

    (new BrewlessServiceProvider(app()))->register();

    expect(config('cache.default'))->toBe('database')
        ->and(config('session.driver'))->toBe('database');
})->with([
    'a host' => [['driver' => 'pgsql', 'host' => 'db.example', 'port' => 5432]],
    'a url' => [['driver' => 'pgsql', 'url' => 'pgsql://user:pass@db.example:5432/shop']],
    'a socket' => [['driver' => 'mysql', 'unix_socket' => '/var/run/mysqld/mysqld.sock']],
    'a SQLite file that is there' => [['driver' => 'sqlite', 'database' => ':memory:']],
]);

test('a store the application named itself is left alone', function (): void {
    withoutADatabase();
    config(['cache.default' => 'array', 'session.driver' => 'array']);

    (new BrewlessServiceProvider(app()))->register();

    expect(config('cache.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array');
});
