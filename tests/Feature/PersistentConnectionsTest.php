<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Database\PersistentConnections;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Database\Connection;

uses(TestCase::class);

test('the setting is off unless the environment turns it on', function (): void {
    expect(config('brewless.database.persistent'))->toBeFalse()
        ->and(config('database.connections.pgsql.options', []))->not->toHaveKey(PDO::ATTR_PERSISTENT);
});

test('BREWLESS_DB_PERSISTENT turns it on for the connections of config/database.php', function (): void {
    config(['brewless.database.persistent' => true, 'database.connections.pgsql' => ['driver' => 'pgsql', 'host' => 'db.example']]);

    (new BrewlessServiceProvider(app()))->register();

    expect(config('database.connections.pgsql.options'))->toBe([PDO::ATTR_PERSISTENT => true]);
});

test('turned on, the postgres and mysql connections stay open, and a choice of the application stands', function (): void {
    $connections = PersistentConnections::configure([
        'pgsql' => ['driver' => 'pgsql', 'options' => [PDO::ATTR_TIMEOUT => 5]],
        'mysql' => ['driver' => 'mysql'],
        'mariadb' => ['driver' => 'mariadb'],
        'reports' => ['driver' => 'pgsql', 'options' => [PDO::ATTR_PERSISTENT => false]],
        'sqlite' => ['driver' => 'sqlite'],
    ]);

    expect($connections['pgsql']['options'])->toBe([PDO::ATTR_TIMEOUT => 5, PDO::ATTR_PERSISTENT => true])
        ->and($connections['mysql']['options'])->toBe([PDO::ATTR_PERSISTENT => true])
        ->and($connections['mariadb']['options'])->toBe([PDO::ATTR_PERSISTENT => true])
        ->and($connections['reports']['options'])->toBe([PDO::ATTR_PERSISTENT => false])
        ->and($connections['sqlite'])->toBe(['driver' => 'sqlite']);
});

test('a persistent connection handed over in the middle of a transaction is rolled back before it is used', function (): void {
    // SQLite stands in for the connection an earlier request left behind.
    $left = new PDO('sqlite::memory:');
    $left->exec('create table votes (id integer)');
    $left->beginTransaction();
    $left->exec('insert into votes values (1)');

    $connection = Connection::getResolver('pgsql')(static fn (): PDO => $left, 'shop', '', ['name' => 'pgsql', 'driver' => 'pgsql', 'options' => [PDO::ATTR_PERSISTENT => true]]);

    expect($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($left->query('select count(*) from votes')->fetchColumn())->toBe(0);
});

test('a connection that is not persistent is left as it is', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->beginTransaction();

    $connection = Connection::getResolver('pgsql')(static fn (): PDO => $pdo, 'shop', '', ['name' => 'pgsql', 'driver' => 'pgsql']);

    expect($connection->getPdo()->inTransaction())->toBeTrue();
});
