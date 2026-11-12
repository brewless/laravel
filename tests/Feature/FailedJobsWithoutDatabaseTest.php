<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Queue\Failed\NullFailedJobProvider;

uses(TestCase::class);

/** Laravel's own defaults: failed jobs in a table, in a SQLite file at this path. */
function failedJobsInSqliteFile(string $path): void
{
    config([
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => ''],
        'queue.default' => 'sync',
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'sqlite',
    ]);

    (new BrewlessServiceProvider(app()))->register();
}

test('an application without a database keeps no failed jobs, and its status does not look for them', function (): void {
    failedJobsInSqliteFile(sys_get_temp_dir().'/brewless-no-database-'.bin2hex(random_bytes(4)).'.sqlite');

    expect(config('queue.failed.driver'))->toBe('null')
        ->and(app('queue.failer'))->toBeInstanceOf(NullFailedJobProvider::class);

    $this->getJson('/_ops/status', $this->ops())
        ->assertOk()
        ->assertJsonPath('jobs_waiting', 0)
        ->assertJsonPath('jobs_failed', 0)
        ->assertJsonPath('failed', []);
});

test('failed jobs stay in the table where the SQLite file is there', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'brewless-database-');

    try {
        failedJobsInSqliteFile($path);

        expect(config('queue.failed.driver'))->toBe('database-uuids');
    } finally {
        unlink($path);
    }
});

test('failed jobs stay in the table of a database in memory or on a server', function (): void {
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.database' => 'testing']);
    (new BrewlessServiceProvider(app()))->register();

    expect(config('queue.failed.driver'))->toBe('database-uuids');

    config([
        'database.connections.pgsql' => ['driver' => 'pgsql', 'host' => 'database.example', 'database' => 'shop'],
        'queue.failed.database' => 'pgsql',
    ]);
    (new BrewlessServiceProvider(app()))->register();

    expect(config('queue.failed.driver'))->toBe('database-uuids');
});
