<?php

declare(strict_types=1);

use Brewless\Laravel\Databases\BrewlessUnreachable;
use Brewless\Laravel\Databases\DatabaseClient;
use Brewless\Laravel\Databases\DatabaseException;
use Brewless\Laravel\Databases\DatabaseInfo;
use Brewless\Laravel\Databases\DatabaseNotReady;
use Brewless\Laravel\Databases\RequestRefused;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(TestCase::class);

const DATABASES_KEY = 'c2hvcC1kYXRhYmFzZXMta2V5LWZvci10ZXN0cy0zMmJ5dGU=';

const DATABASES_ENVIRONMENT = '01k8databasesenvironment01';

beforeEach(function (): void {
    config([
        'brewless.databases.url' => 'https://acme.brewless.eu/',
        'brewless.databases.environment' => DATABASES_ENVIRONMENT,
        'brewless.databases.key' => DATABASES_KEY,
    ]);

    Http::preventStrayRequests();
});

function databases(): DatabaseClient
{
    return resolve(DatabaseClient::class);
}

/**
 * @return array<string, mixed>
 */
function answer(string $status, ?string $host = null): array
{
    return ['name' => 'shop-one', 'status' => $status, 'host' => $host, 'port' => $host === null ? null : 5432];
}

test('every request is signed over its method, path, time and empty body with the key as it stands, and the key itself is never sent', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-23 10:00:00 UTC'));
    Http::fake(['acme.brewless.eu/*' => Http::response(answer('creating'), 202)]);

    databases()->create('shop-one');

    Http::assertSent(static function (Request $request): bool {
        $timestamp = (string) (new DateTimeImmutable('2026-10-23 10:00:00 UTC'))->getTimestamp();
        $expected = hash_hmac('sha256', "PUT\n/api/runtime/databases/shop-one\n".$timestamp."\n".hash('sha256', ''), DATABASES_KEY);

        return $request->method() === 'PUT'
            && $request->url() === 'https://acme.brewless.eu/api/runtime/databases/shop-one'
            && $request->header('X-Brewless-Environment') === [DATABASES_ENVIRONMENT]
            && $request->header('X-Brewless-Timestamp') === [$timestamp]
            && $request->header('X-Brewless-Signature') === [$expected]
            && $request->header('Accept') === ['application/json']
            && $request->body() === ''
            && ! str_contains(json_encode($request->headers()).$request->url(), DATABASES_KEY);
    });
});

test('the signature is the one brewless checks, for each method', function (string $method): void {
    expect(DatabaseClient::signature($method, '/api/runtime/databases/shop-one', '1792749600', '', 'key'))
        ->toBe(hash_hmac('sha256', $method."\n/api/runtime/databases/shop-one\n1792749600\n".hash('sha256', ''), 'key'));
})->with(['PUT', 'GET', 'DELETE']);

test('create reads the database brewless answers with', function (): void {
    Http::fake(['*' => Http::response(answer('creating'), 202)]);

    expect(databases()->create('shop-one'))->toEqual(new DatabaseInfo('shop-one', 'creating'));
});

test('find reads where the database stands, and is null for one brewless did not make', function (): void {
    Http::fake([
        '*/shop-one' => Http::response(answer('ready', 'db-1.pg.sdb.fr-par.scw.cloud')),
        '*/shop-two' => Http::response(['message' => 'Not Found'], 404),
    ]);

    expect(databases()->find('shop-one'))->toEqual(new DatabaseInfo('shop-one', 'ready', 'db-1.pg.sdb.fr-par.scw.cloud', 5432))
        ->and(databases()->find('shop-two'))->toBeNull();

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});

test('delete removes it, and one that is gone already is fine', function (int $status): void {
    Http::fake(['*' => Http::response(['name' => 'shop-one', 'status' => 'deleting'], $status)]);

    databases()->delete('shop-one');

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE');
})->with([202, 404]);

test('waiting looks every two seconds until the database is ready', function (): void {
    Sleep::fake();
    Http::fakeSequence()
        ->push(answer('creating'))
        ->push(answer('creating'))
        ->push(answer('ready'))
        ->push(answer('ready', 'db-1.pg.sdb.fr-par.scw.cloud'));

    $database = databases()->waitUntilReady('shop-one');

    expect($database->host)->toBe('db-1.pg.sdb.fr-par.scw.cloud')
        ->and($database->isReady())->toBeTrue();

    Http::assertSentCount(4);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
});

test('waiting gives up after the time asked for', function (): void {
    Sleep::fake();
    Http::fake(['*' => Http::response(answer('creating'))]);

    expect(static fn () => databases()->waitUntilReady('shop-one', 10))
        ->toThrow(DatabaseNotReady::class, 'was not ready after 10 seconds');

    Http::assertSentCount(6);
});

test('waiting stops at once on an error, a removal or a database brewless does not know', function (int $code, array $body, string $message): void {
    Sleep::fake();
    Http::fake(['*' => Http::response($body, $code)]);

    expect(static fn () => databases()->waitUntilReady('shop-one'))->toThrow(DatabaseNotReady::class, $message);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    'an error' => [200, answer('error'), 'it is error'],
    'being removed' => [200, answer('deleting'), 'it is deleting'],
    'not known' => [404, ['message' => 'Not Found'], 'not known to Brewless'],
]);

test('a refusal says what brewless answered', function (int $status, string $message): void {
    Http::fake(['*' => Http::response(['message' => $message], $status)]);

    try {
        databases()->create('shop-one');
        $this->fail('Expected a refusal.');
    } catch (RequestRefused $refused) {
        expect($refused->status)->toBe($status)
            ->and($refused->getMessage())->toContain((string) $status, $message)
            ->and($refused->getMessage())->not->toContain(DATABASES_KEY);
    }
})->with([
    'a wrong signature' => [401, 'This request is not signed by an environment that may ask for databases.'],
    'a name it does not make' => [422, 'The name of the database has to start with shop-.'],
    'a blocked organisation' => [423, 'This organisation is blocked.'],
]);

test('brewless out of reach, busy or throttling is unreachable, and safe to ask again', function (Closure $answer): void {
    Http::fake(['*' => $answer]);

    expect(static fn () => databases()->create('shop-one'))->toThrow(BrewlessUnreachable::class);
})->with([
    'no connection' => [static fn () => throw new ConnectionException('Connection timed out after 5001 milliseconds')],
    'a server error' => [static fn () => Http::response(['message' => 'down'], 503)],
    'too many requests' => [static fn () => Http::response(['message' => 'slow down'], 429)],
]);

test('requests have a time limit, and one to connect', function (): void {
    config(['brewless.databases.timeout' => 7, 'brewless.databases.connect_timeout' => 3]);
    $options = [];
    Http::fake(static function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(answer('creating'), 202);
    });

    databases()->create('shop-one');

    expect($options)->toMatchArray(['timeout' => 7, 'connect_timeout' => 3]);
});

test('without the three variables the client says so and sends nothing', function (string $missing): void {
    config(['brewless.databases.'.$missing => null]);
    Http::fake();

    expect(databases()->configured())->toBeFalse()
        ->and(static fn () => databases()->create('shop-one'))->toThrow(DatabaseException::class, 'not configured');

    Http::assertNothingSent();
})->with(['url', 'environment', 'key']);

test('with the three variables it is configured', function (): void {
    expect(databases()->configured())->toBeTrue();
});

test('a name brewless does not make is refused before anything is sent', function (string $name): void {
    Http::fake();

    expect(static fn () => databases()->create($name))->toThrow(DatabaseException::class, 'is not a database name');

    Http::assertNothingSent();
})->with(['Shop-One', 'shop_one', '../shop-one', '', "shop-one\n", 'shop-'.str_repeat('a', 59)]);

test('the variables reach the client through the configuration', function (): void {
    $config = require __DIR__.'/../../config/brewless.php';

    expect(array_keys($config['databases']))->toBe(['url', 'environment', 'key', 'timeout', 'connect_timeout']);
});

test('the signature of a fixed request is the one brewless computes for it', function (): void {
    // The same vector is in Brewless's own tests (RuntimeDatabasesTest), so the two cannot drift apart.
    expect(DatabaseClient::signature('PUT', '/api/runtime/databases/shop-one', '1792749600', '', DATABASES_KEY))
        ->toBe('ed795b610e83a0d86233eee65a3a7203218dcb36ab0d4c4d1f681070d99418f8');
});
