<?php

declare(strict_types=1);

use Brewless\Laravel\Database\PostgresConnection;
use Brewless\Laravel\Database\RetriesLostConnections;
use Brewless\Laravel\Ops\ReleaseFailed;
use Brewless\Laravel\Ops\Runtime;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

uses(TestCase::class);

/**
 * A PostgreSQL connection whose database refuses the first tries, as during
 * a maintenance restart, and then answers. SQLite stands in for the answer.
 *
 * @param  int  $refusals  how many tries fail before one succeeds
 * @param  int  $tries  counted by reference
 */
function flakyConnection(int $refusals, ?int &$tries, string $message = 'SQLSTATE[08006] [7] connection to server at "10.0.0.1", port 5432 failed: Connection refused'): PostgresConnection
{
    $tries = 0;
    $pdo = static function () use ($refusals, &$tries, $message): PDO {
        $tries++;

        if ($tries <= $refusals) {
            throw new PDOException($message);
        }

        return new PDO('sqlite::memory:');
    };

    $connection = new PostgresConnection($pdo, 'shop', '', ['name' => 'pgsql', 'driver' => 'pgsql']);
    $connection->setReconnector(static fn (Connection $connection) => $connection->setPdo($pdo)->setReadPdo($pdo));

    return $connection;
}

beforeEach(function (): void {
    Sleep::fake();
});

test('the pgsql and mysql drivers make connections that try again after a lost connection', function (): void {
    expect(Connection::getResolver('pgsql'))->not->toBeNull()
        ->and(Connection::getResolver('mysql'))->not->toBeNull();
});

test('a query is tried again with growing pauses until the database answers', function (): void {
    $connection = flakyConnection(4, $tries);

    expect($connection->select('select 1 as one'))->toEqual([(object) ['one' => 1]])
        ->and($tries)->toBe(5);

    // At once, then 1, 2 and 4 seconds later.
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
});

test('postgres saying it shuts down counts as going away too', function (): void {
    $connection = flakyConnection(2, $tries, 'SQLSTATE[57P01]: Admin shutdown: 7 FATAL: terminating connection due to administrator command');

    expect($connection->select('select 1 as one'))->toHaveCount(1)
        ->and($tries)->toBe(3);
});

test('a private network that does not carry traffic yet after a cold start counts too', function (string $message): void {
    $connection = flakyConnection(2, $tries, $message);

    expect($connection->select('select 1 as one'))->toHaveCount(1)
        ->and($tries)->toBe(3);
})->with([
    'postgres' => "SQLSTATE[08006] [7] connection to server at \"172.16.0.2\", port 5432 failed: No route to host\n\tIs the server running on that host and accepting TCP/IP connections?",
    'postgres, no network' => 'SQLSTATE[08006] [7] connection to server at "172.16.0.2", port 5432 failed: Network is unreachable',
    'mysql' => 'SQLSTATE[HY000] [2002] No route to host',
]);

test('a serverless database that is still waking up counts too', function (): void {
    $connection = flakyConnection(2, $tries, "SQLSTATE[08006] [7] connection to server at \"314b0b36.pg.sdb.fr-par.scw.cloud\" (163.172.142.91), port 5432 failed: timeout expired\nconnection to server at \"314b0b36.pg.sdb.fr-par.scw.cloud\" (212.47.254.106), port 5432 failed: timeout expired");

    expect($connection->select('select 1 as one'))->toHaveCount(1)
        ->and($tries)->toBe(3);
});

test('after the seconds of brewless.database.retry_seconds it gives up with the error', function (): void {
    config(['brewless.database.retry_seconds' => 10]);
    $connection = flakyConnection(100, $tries);

    expect(fn () => $connection->select('select 1'))->toThrow(QueryException::class, 'Connection refused');

    // 1 + 2 + 4 seconds waited; a pause of 8 more would pass the 10, so it stops there.
    // The query itself, at once, and after 1, 2 and 4 seconds.
    expect($tries)->toBe(5);
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
});

test('0 keeps Laravel\'s own single try', function (): void {
    config(['brewless.database.retry_seconds' => 0]);
    $connection = flakyConnection(1, $tries);

    expect($connection->select('select 1 as one'))->toHaveCount(1)
        ->and($tries)->toBe(2);
    Sleep::assertNeverSlept();
});

test('inside a transaction a lost connection is not tried again', function (): void {
    $pdo = new class('sqlite::memory:') extends PDO
    {
        public bool $gone = false;

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return $this->gone ? throw new PDOException('SQLSTATE[08006] server closed the connection unexpectedly') : parent::prepare($query, $options);
        }
    };
    $connection = new PostgresConnection($pdo, 'shop', '', ['name' => 'pgsql', 'driver' => 'pgsql']);
    $connection->setReconnector(static fn (Connection $connection) => $connection);

    $connection->beginTransaction();
    $pdo->gone = true;

    expect(fn () => $connection->select('select 1'))->toThrow(QueryException::class, 'server closed the connection');
    Sleep::assertNeverSlept();
});

test('an error of another kind is not tried again', function (): void {
    $connection = flakyConnection(0, $tries);

    expect(fn () => $connection->select('select * from nothing_here'))->toThrow(QueryException::class, 'no such table');
    Sleep::assertNeverSlept();
});

test('a connection class of the application\'s own that uses the trait tries again too', function (): void {
    $tries = 0;
    $pdo = static function () use (&$tries): PDO {
        $tries++;

        if ($tries <= 2) {
            throw new PDOException('SQLSTATE[08006] [7] connection to server at "172.16.0.2", port 5432 failed: No route to host');
        }

        return new PDO('sqlite::memory:');
    };
    $connection = new class($pdo, 'shop', '', ['name' => 'pgsql', 'driver' => 'pgsql']) extends Illuminate\Database\PostgresConnection
    {
        use RetriesLostConnections;
    };
    $connection->setReconnector(static fn (Connection $connection) => $connection->setPdo($pdo)->setReadPdo($pdo));

    expect($connection->select('select 1 as one'))->toHaveCount(1)
        ->and($tries)->toBe(3);
});

test('a release command whose first query finds no route to the database waits and runs', function (): void {
    $tries = 0;
    $pdo = static function () use (&$tries): PDO {
        $tries++;

        if ($tries <= 3) {
            throw new PDOException('SQLSTATE[08006] [7] connection to server at "172.16.0.2", port 5432 failed: No route to host');
        }

        return new PDO('sqlite::memory:');
    };
    // A new connection each time, as the driver makes one: Laravel reconnects by asking for a fresh one.
    app('db')->extend('release-database', static fn (array $config, string $name) => new PostgresConnection($pdo, 'shop', '', [...$config, 'name' => $name]));
    config(['database.connections.release-database' => ['driver' => 'pgsql'], 'brewless.ops.release_commands' => ['shop:reach-database']]);
    Artisan::command('shop:reach-database', fn () => DB::connection('release-database')->select('select 1'));

    expect(resolve(Runtime::class)->release())->toHaveKey('seconds')
        ->and($tries)->toBe(4);
});

test('a release command whose SQL is wrong fails the release at once', function (): void {
    $connection = flakyConnection(0, $tries);
    app('db')->extend('release-database', static fn () => $connection);
    config(['database.connections.release-database' => ['driver' => 'pgsql'], 'brewless.ops.release_commands' => ['shop:reach-database']]);
    Artisan::command('shop:reach-database', fn () => DB::connection('release-database')->select('select * from nothing_here'));

    expect(fn () => resolve(Runtime::class)->release())->toThrow(ReleaseFailed::class, 'shop:reach-database');
    Sleep::assertNeverSlept();
});
