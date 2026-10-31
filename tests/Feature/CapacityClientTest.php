<?php

declare(strict_types=1);

use Brewless\Laravel\Capacity\Boost;
use Brewless\Laravel\Capacity\CapacityApi;
use Brewless\Laravel\Capacity\CapacityClient;
use Brewless\Laravel\Capacity\CapacityException;
use Brewless\Laravel\Capacity\CapacityRefused;
use Brewless\Laravel\Capacity\CapacityUnavailable;
use Brewless\Laravel\Capacity\CapacityUnreachable;
use Brewless\Laravel\Capacity\FakeCapacity;
use Brewless\Laravel\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\AssertionFailedError;

uses(TestCase::class);

const CAPACITY_KEY = 'Y2FwYWNpdHkta2V5LWZvci10ZXN0cy0zMi1ieXRlcy0hIQ==';

const CAPACITY_ENVIRONMENT = '01k8capacityenvironment001';

beforeEach(function (): void {
    config([
        'brewless.capacity.url' => 'https://acme.brewless.eu/',
        'brewless.capacity.environment' => CAPACITY_ENVIRONMENT,
        'brewless.capacity.key' => CAPACITY_KEY,
    ]);

    Http::preventStrayRequests();
});

function capacity(): CapacityApi
{
    return resolve(CapacityApi::class);
}

/**
 * A boost as Brewless answers for it.
 *
 * @param  array<string, mixed>  $changes
 * @return array{data: array<string, mixed>}
 */
function boostAnswer(array $changes = []): array
{
    return ['data' => [...[
        'key' => 'event-123-sale',
        'name' => 'Sale of event 123',
        'database' => 'shop-main',
        'environment' => 'production',
        'source' => 'api',
        'status' => 'scheduled',
        'ready' => false,
        'ready_at' => null,
        'min_vcpu' => 4,
        'max_vcpu' => 15,
        'starts_at' => '2027-05-01T10:00:00+02:00',
        'ends_at' => '2027-05-01T12:00:00+02:00',
        'prewarm_minutes' => 15,
        'cooldown_minutes' => 30,
        'window_starts_at' => '2027-05-01T09:45:00+02:00',
        'window_ends_at' => '2027-05-01T12:30:00+02:00',
        'cpu_current' => null,
        'failure_reason' => null,
        'cancelled_at' => null,
        'estimate' => ['floor_cents' => 151, 'ceiling_cents' => 567, 'minutes' => 165, 'currency' => 'EUR'],
        'created_at' => '2026-10-31T14:00:00+01:00',
    ], ...$changes]];
}

function signed(Request $request): bool
{
    $timestamp = $request->header('X-Brewless-Timestamp')[0] ?? '';
    $path = (string) parse_url($request->url(), PHP_URL_PATH);
    $expected = hash_hmac('sha256', $request->method()."\n".$path."\n".$timestamp."\n".hash('sha256', $request->body()), CAPACITY_KEY);

    return $request->header('X-Brewless-Environment') === [CAPACITY_ENVIRONMENT]
        && $request->header('X-Brewless-Signature') === [$expected];
}

test('the signature of a fixed request is the one brewless computes for it', function (): void {
    // The same vector is in Brewless's own tests (RuntimeCapacityTest), so the two cannot drift apart.
    $expected = hash_hmac('sha256', "POST\n/api/runtime/capacity/boosts\n1793000000\n".hash('sha256', '{"key":"event-1-sale"}'), 'test-key');

    expect(CapacityClient::signature('POST', '/api/runtime/capacity/boosts', '1793000000', '{"key":"event-1-sale"}', 'test-key'))
        ->toBe($expected)
        ->toBe('338a4e6102b33f2897a4b23e39764536cad944fee9ffc156244b36bac741268f');
});

test('scheduling a boost posts it signed over the exact body, with the times and their offset', function (): void {
    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(1793000000));
    Http::fake(['acme.brewless.eu/*' => Http::response(boostAnswer(), 201)]);

    $starts = CarbonImmutable::parse('2027-05-01 10:00', 'Europe/Amsterdam');
    $boost = capacity()->schedule('event-123-sale', $starts, $starts->addHours(2), min: 4, max: 15, name: 'Sale of event 123');

    Http::assertSent(static function (Request $request): bool {
        $body = '{"key":"event-123-sale","name":"Sale of event 123","min_vcpu":4,"max_vcpu":15,"starts_at":"2027-05-01T10:00:00+02:00","ends_at":"2027-05-01T12:00:00+02:00","prewarm_minutes":15,"cooldown_minutes":30}';
        $expected = hash_hmac('sha256', "POST\n/api/runtime/capacity/boosts\n1793000000\n".hash('sha256', $body), CAPACITY_KEY);

        return $request->method() === 'POST'
            && $request->url() === 'https://acme.brewless.eu/api/runtime/capacity/boosts'
            && $request->body() === $body
            && $request->header('Content-Type') === ['application/json']
            && $request->header('Accept') === ['application/json']
            && $request->header('X-Brewless-Environment') === [CAPACITY_ENVIRONMENT]
            && $request->header('X-Brewless-Timestamp') === ['1793000000']
            && $request->header('X-Brewless-Signature') === [$expected]
            && ! str_contains(json_encode($request->headers()).$request->body(), CAPACITY_KEY);
    });

    expect($boost)->toBeInstanceOf(Boost::class)
        ->and($boost->key)->toBe('event-123-sale')
        ->and($boost->status)->toBe(Boost::SCHEDULED)
        ->and($boost->ready)->toBeFalse()
        ->and($boost->minVcpu)->toBe(4)
        ->and($boost->maxVcpu)->toBe(15)
        ->and($boost->startsAt->equalTo($starts))->toBeTrue()
        ->and($boost->windowStartsAt->toIso8601String())->toBe('2027-05-01T09:45:00+02:00')
        ->and($boost->estimateFloorCents)->toBe(151)
        ->and($boost->estimateCeilingCents)->toBe(567)
        ->and($boost->isOver())->toBeFalse();
});

test('a boost that starts now sends minutes and no start', function (): void {
    Http::fake(['*' => Http::response(boostAnswer(['status' => 'active']), 201)]);

    capacity()->boost('newsletter', 60, min: 2, max: 8, database: 'shop-main');

    Http::assertSent(static fn (Request $request): bool => signed($request)
        && $request->method() === 'POST'
        && $request->data() === ['key' => 'newsletter', 'database' => 'shop-main', 'min_vcpu' => 2, 'max_vcpu' => 8, 'minutes' => 60]);
});

test('rescheduling, updating, extending and cancelling go to the boost by its key', function (Closure $call, string $method, string $path, array $data): void {
    Http::fake(['*' => Http::response(boostAnswer())]);

    $call(capacity());

    Http::assertSent(static fn (Request $request): bool => signed($request)
        && $request->method() === $method
        && $request->url() === 'https://acme.brewless.eu/api/runtime/capacity/boosts/event-123-sale'.$path
        && $request->data() === $data);
})->with([
    'reschedule' => [
        static fn (CapacityApi $capacity) => $capacity->reschedule('event-123-sale', CarbonImmutable::parse('2027-05-02T10:00:00+02:00'), CarbonImmutable::parse('2027-05-02T12:00:00+02:00')),
        'PATCH', '', ['starts_at' => '2027-05-02T10:00:00+02:00', 'ends_at' => '2027-05-02T12:00:00+02:00'],
    ],
    'update' => [
        static fn (CapacityApi $capacity) => $capacity->update('event-123-sale', ['max_vcpu' => 8, 'prewarm_minutes' => 30]),
        'PATCH', '', ['max_vcpu' => 8, 'prewarm_minutes' => 30],
    ],
    'extend' => [
        static fn (CapacityApi $capacity) => $capacity->extend('event-123-sale', CarbonImmutable::parse('2027-05-01T13:00:00+02:00')),
        'POST', '/extend', ['ends_at' => '2027-05-01T13:00:00+02:00'],
    ],
    'cancel' => [
        static fn (CapacityApi $capacity) => $capacity->cancel('event-123-sale'),
        'DELETE', '', [],
    ],
]);

test('a request without a body is signed over an empty one', function (): void {
    Http::fake(['*' => Http::response(boostAnswer(['status' => 'cancelled', 'cancelled_at' => '2026-10-31T15:00:00+01:00']))]);

    $boost = capacity()->cancel('event-123-sale');

    Http::assertSent(static fn (Request $request): bool => signed($request) && $request->body() === '');
    expect($boost->status)->toBe(Boost::CANCELLED)
        ->and($boost->cancelledAt?->toIso8601String())->toBe('2026-10-31T15:00:00+01:00')
        ->and($boost->isOver())->toBeTrue();
});

test('status is null for a boost brewless does not know', function (): void {
    Http::fake(['*' => Http::response(['message' => 'There is no boost event-9-sale.'], 404)]);

    expect(capacity()->status('event-9-sale'))->toBeNull()
        ->and(capacity()->ready('event-9-sale'))->toBeFalse();

    Http::assertSent(static fn (Request $request): bool => signed($request) && $request->method() === 'GET');
});

test('ready is true only when brewless says the database reached the minimum', function (array $answer, bool $ready): void {
    Http::fake(['*' => Http::response(boostAnswer($answer))]);

    expect(capacity()->ready('event-123-sale'))->toBe($ready);
})->with([
    'ready' => [['status' => 'ready', 'ready' => true, 'ready_at' => '2027-05-01T09:47:00+02:00', 'cpu_current' => 4.0], true],
    'prewarming' => [['status' => 'prewarming', 'ready' => false, 'cpu_current' => 2.5], false],
    'not reached' => [['status' => 'not_reached', 'ready' => false], false],
]);

test('the databases come with their bounds now', function (): void {
    Http::fake(['*' => Http::response([
        'data' => [['name' => 'shop-main', 'region' => 'fr-par', 'shared' => false, 'baseline' => ['min' => 0, 'max' => 4], 'effective' => ['min' => 4, 'max' => 15], 'cpu_current' => 3.5, 'status' => 'ready']],
        'limits' => ['max_min_vcpu' => 15, 'max_max_vcpu' => 15, 'max_hours' => 12, 'monthly_hours' => 100, 'horizon_days' => 365],
        'used_hours_this_month' => 2.75,
    ])]);

    $databases = capacity()->databases();

    expect($databases)->toHaveCount(1)
        ->and($databases[0]->name)->toBe('shop-main')
        ->and($databases[0]->baselineMax)->toBe(4)
        ->and($databases[0]->effectiveMin)->toBe(4)
        ->and($databases[0]->effectiveMax)->toBe(15)
        ->and($databases[0]->cpuCurrent)->toBe(3.5);

    Http::assertSent(static fn (Request $request): bool => signed($request) && $request->url() === 'https://acme.brewless.eu/api/runtime/capacity/databases');
});

test('brewless busy, throttling or out of reach is tried again, signed anew each time', function (Closure $first): void {
    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(1793000000));
    Sleep::fake(syncWithCarbon: true);
    Http::fakeSequence()
        ->pushResponse($first())
        ->push(boostAnswer(), 201);

    $boost = capacity()->boost('event-123-sale', 60, 4, 15);

    expect($boost->key)->toBe('event-123-sale');
    Sleep::assertSequence([Sleep::for(500)->milliseconds()]);

    $timestamps = [];
    Http::assertSent(static function (Request $request) use (&$timestamps): bool {
        $timestamps[] = $request->header('X-Brewless-Timestamp')[0];

        return signed($request);
    });
    Http::assertSentCount(2);
    expect($timestamps)->toBe(['1793000000', '1793000001']);
})->with([
    'a server error' => [static fn () => Http::response(['message' => 'Scaleway could not be reached.'], 503)],
    'too many requests' => [static fn () => Http::response(['message' => 'Too Many Attempts.'], 429)],
]);

test('a try after one that failed to connect is signed anew too', function (): void {
    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(1793000000));
    Sleep::fake(syncWithCarbon: true);
    $tries = 0;
    Http::fake(static function () use (&$tries) {
        return ++$tries === 1 ? throw new ConnectionException('Connection timed out') : Http::response(boostAnswer(), 201);
    });

    capacity()->boost('event-123-sale', 60, 4, 15);

    expect($tries)->toBe(2);
    Http::assertSentCount(1);
});

test('a retry gets a timestamp of its own even when the clock did not move', function (): void {
    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(1793000000));
    Sleep::fake();
    Http::fakeSequence()->push(['message' => 'down'], 503)->push(boostAnswer(), 201);

    capacity()->boost('event-123-sale', 60, 4, 15);

    $timestamps = [];
    Http::assertSent(static function (Request $request) use (&$timestamps): bool {
        $timestamps[] = $request->header('X-Brewless-Timestamp')[0];

        return signed($request);
    });
    expect($timestamps)->toBe(['1793000000', '1793000001']);
});

test('after the last try brewless is unreachable', function (): void {
    Sleep::fake();
    Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

    expect(static fn () => capacity()->status('event-123-sale'))->toThrow(CapacityUnreachable::class, 'after 4 tries');

    Http::assertSentCount(4);
    Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
});

test('a refusal is never tried again and says what brewless answered', function (int $status, string $message): void {
    Sleep::fake();
    Http::fake(['*' => Http::response(['message' => $message], $status)]);

    try {
        capacity()->schedule('event-123-sale', now()->addDay(), now()->addDay()->addHours(2), 4, 15);
        $this->fail('Expected a refusal.');
    } catch (CapacityRefused $refused) {
        expect($refused->status)->toBe($status)
            ->and($refused->getMessage())->toContain((string) $status, $message)
            ->and($refused->getMessage())->not->toContain(CAPACITY_KEY);
    }

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    'a wrong signature' => [401, 'This request is not signed by the environment.'],
    'a plan without boosts' => [403, 'Capacity boosts come with Business and up.'],
]);

test('a key used before for another boost is a conflict', function (): void {
    Http::fake(['*' => Http::response(['message' => 'The boost event-123-sale was asked for with other values.'], 409)]);

    try {
        capacity()->schedule('event-123-sale', now()->addDay(), now()->addDay()->addHours(2), 4, 15);
        $this->fail('Expected a conflict.');
    } catch (CapacityRefused $refused) {
        expect($refused->status)->toBe(409)
            ->and($refused->isConflict())->toBeTrue()
            ->and($refused->isInvalid())->toBeFalse()
            ->and($refused->errors)->toBe([]);
    }
});

test('a value brewless does not take is refused with its fields', function (): void {
    Http::fake(['*' => Http::response(['message' => 'The max vcpu may not be greater than 15.', 'errors' => ['max_vcpu' => ['The max vcpu may not be greater than 15.']]], 422)]);

    try {
        capacity()->boost('event-123-sale', 60, 4, 16);
        $this->fail('Expected a refusal.');
    } catch (CapacityRefused $refused) {
        expect($refused->status)->toBe(422)
            ->and($refused->isInvalid())->toBeTrue()
            ->and($refused->isConflict())->toBeFalse()
            ->and($refused->errors)->toBe(['max_vcpu' => ['The max vcpu may not be greater than 15.']]);
    }
});

test('without the three variables the client is unavailable and sends nothing', function (string $missing): void {
    config(['brewless.capacity.'.$missing => null]);
    Http::fake();

    expect(capacity()->configured())->toBeFalse()
        ->and(static fn () => capacity()->boost('event-123-sale', 60, 4, 15))->toThrow(CapacityUnavailable::class, 'BREWLESS_CAPACITY_KEY')
        ->and(new CapacityUnavailable)->toBeInstanceOf(CapacityException::class);

    Http::assertNothingSent();
})->with(['url', 'environment', 'key']);

test('with the three variables it is configured', function (): void {
    expect(capacity()->configured())->toBeTrue()
        ->and(capacity())->toBeInstanceOf(CapacityClient::class)
        ->and(resolve(CapacityClient::class)->configured())->toBeTrue();
});

test('a key brewless does not take is refused before anything is sent', function (string $key): void {
    Http::fake();

    expect(static fn () => capacity()->status($key))->toThrow(CapacityException::class, 'is not a boost key');

    Http::assertNothingSent();
})->with(['', '..', '.', 'event/1', 'event 1', "event-1\n", 'event?1', str_repeat('a', 101)]);

test('an answer without a boost is not taken for one', function (): void {
    Http::fake(['*' => Http::response(['data' => ['key' => 'event-123-sale']])]);

    expect(static fn () => capacity()->status('event-123-sale'))->toThrow(CapacityException::class, 'without the boost');
});

test('the variables reach the client through the configuration', function (): void {
    $config = require __DIR__.'/../../config/brewless.php';

    expect(array_keys($config['capacity']))->toBe(['url', 'environment', 'key', 'timeout', 'connect_timeout', 'retry_milliseconds']);
});

test('the fake keeps what the application asked for and sends nothing', function (): void {
    Http::fake();
    $fake = CapacityClient::fake();
    $starts = CarbonImmutable::parse('2027-05-01T10:00:00+02:00');

    capacity()->schedule('event-1-sale', $starts, $starts->addHours(2), min: 4, max: 15);
    capacity()->reschedule('event-1-sale', $starts->addDay(), $starts->addDay()->addHours(2));
    capacity()->boost('newsletter', 30, 2, 8);
    capacity()->cancel('newsletter');

    expect(capacity())->toBe($fake)
        ->and($fake->status('event-1-sale')?->startsAt->equalTo($starts->addDay()))->toBeTrue()
        ->and($fake->status('event-1-sale')?->windowStartsAt->equalTo($starts->addDay()->subMinutes(15)))->toBeTrue()
        ->and($fake->status('unknown'))->toBeNull();

    $fake->assertScheduled('event-1-sale');
    $fake->assertScheduled('event-1-sale', static fn (Boost $boost): bool => $boost->minVcpu === 4 && $boost->maxVcpu === 15);
    $fake->assertScheduled('newsletter');
    $fake->assertNotScheduled('event-2-sale');
    $fake->assertCancelled('newsletter');
    $fake->assertNotCancelled('event-1-sale');

    Http::assertNothingSent();
});

test('the fake fails an assertion that does not hold', function (Closure $assertion): void {
    $fake = CapacityClient::fake();
    $fake->schedule('event-1-sale', now()->addDay(), now()->addDay()->addHours(2), 4, 15);

    expect(static fn () => $assertion($fake))->toThrow(AssertionFailedError::class);
})->with([
    'scheduled' => [static fn (FakeCapacity $fake) => $fake->assertScheduled('event-2-sale')],
    'scheduled as expected' => [static fn (FakeCapacity $fake) => $fake->assertScheduled('event-1-sale', static fn (Boost $boost): bool => $boost->maxVcpu === 8)],
    'not scheduled' => [static fn (FakeCapacity $fake) => $fake->assertNotScheduled('event-1-sale')],
    'nothing scheduled' => [static fn (FakeCapacity $fake) => $fake->assertNothingScheduled()],
    'cancelled' => [static fn (FakeCapacity $fake) => $fake->assertCancelled('event-1-sale')],
]);

test('nothing scheduled holds on a fresh fake', function (): void {
    CapacityClient::fake()->assertNothingScheduled();
});

test('the fake is ready only where the test says so', function (): void {
    $fake = CapacityClient::fake();
    $fake->schedule('event-1-sale', now()->addHour(), now()->addHours(3), 4, 15);

    expect($fake->ready('event-1-sale'))->toBeFalse();

    $fake->markReady('event-1-sale');

    expect($fake->ready('event-1-sale'))->toBeTrue()
        ->and($fake->status('event-1-sale')?->cpuCurrent)->toBe(4.0)
        ->and($fake->ready('event-2-sale'))->toBeFalse();

    $fake->markReady('event-1-sale', false);

    expect($fake->ready('event-1-sale'))->toBeFalse();
});

test('the fake can be out of reach, to test that the application goes on', function (): void {
    $fake = CapacityClient::fake()->unreachable();

    expect(static fn () => $fake->boost('event-1-sale', 60, 4, 15))->toThrow(CapacityUnreachable::class);
    $fake->assertNothingScheduled();
});

test('the fake refuses a boost it does not know, like brewless', function (): void {
    expect(static fn () => CapacityClient::fake()->cancel('event-1-sale'))->toThrow(CapacityRefused::class, '404');
});
