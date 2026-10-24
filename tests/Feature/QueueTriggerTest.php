<?php

declare(strict_types=1);

use Brewless\Laravel\Queue\Deliveries;
use Brewless\Laravel\Queue\JobSignature;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(TestCase::class);

final class RememberThatItRan implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $note) {}

    public function handle(): void
    {
        Cache::put('ran', $this->note);
    }
}

final class AlwaysFails implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(): void
    {
        throw new RuntimeException('it failed');
    }
}

final class CountsItsRuns implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::increment('runs');
    }
}

final class FailsUntilFixed implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        if (Cache::get('fixed') !== true) {
            throw new RuntimeException('not fixed yet');
        }

        Cache::put('ran', 'fixed');
    }
}

/**
 * Sign a payload again with the application's key, as only the application can.
 *
 * @param  array<string, mixed>  $payload
 */
function signedAgain(array $payload): string
{
    unset($payload[JobSignature::KEY]);

    return (string) json_encode([...$payload, JobSignature::KEY => JobSignature::sign($payload, (string) JobSignature::key())]);
}

/**
 * Queue a job as the application does, and take its message off the queue as
 * the provider's trigger would before it posts it to the container.
 */
function queuedMessage(object $job): string
{
    Queue::connection('database')->push($job, '', 'mails');

    $row = DB::table('jobs')->latest('id')->first();
    DB::table('jobs')->delete();

    return (string) $row->payload;
}

/**
 * Post a message the way Scaleway's queue trigger does: the raw body, no secret.
 */
function deliver(string $message): TestResponse
{
    return test()->call('POST', '/', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_USER_AGENT' => 'SQSTrigger/1.0 (trigger: t1)', 'HTTP_X_TRIGGER_ID' => 't1'], $message);
}

test('every job the application queues is signed, with the queue it went on', function (): void {
    $payload = json_decode(queuedMessage(new RememberThatItRan('hello')), true);

    expect($payload[JobSignature::QUEUE])->toBe('mails')
        ->and(JobSignature::holdsForApplication($payload))->toBeTrue()
        ->and(JobSignature::holds($payload, 'another-secret'))->toBeFalse();
});

test('a signed job delivered by the queue trigger runs, and the trigger is told it arrived', function (): void {
    deliver(queuedMessage(new RememberThatItRan('hello')))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('state', 'done');

    expect(Cache::get('ran'))->toBe('hello')
        // Done is done: nothing goes back on the queue.
        ->and(DB::table('jobs')->count())->toBe(0);
});

/**
 * Write a message again the way another JSON encoder would: keys in another
 * order, slashes and non-ASCII characters escaped differently, and with
 * space between the parts.
 */
function reencoded(string $message): string
{
    $sorted = function (mixed $value) use (&$sorted): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            krsort($value);
        }

        return array_map($sorted, $value);
    };

    return (string) json_encode($sorted(json_decode($message, true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

test('a job still runs when the provider wrote its message again with another JSON encoder', function (): void {
    $message = queuedMessage(new RememberThatItRan('héllo/wörld'));
    $again = reencoded($message);

    expect($again)->not->toBe($message);

    deliver($again)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('state', 'done');

    expect(Cache::get('ran'))->toBe('héllo/wörld');
});

test('a dropped job says in the log what was different, never what it carries', function (): void {
    Log::spy();

    $payload = json_decode(queuedMessage(new RememberThatItRan('hello')), true);
    $payload['data']['command'] = serialize(new RememberThatItRan('changed'));
    deliver((string) json_encode($payload))->assertJsonPath('dropped', true);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($payload): bool {
        return $message === 'brewless: a queue message without a valid signature was dropped'
            && $context['uuid'] === $payload['uuid']
            && $context['signed'] === true
            && $context['keys'] === array_keys($payload)
            && is_int($context['bytes'])
            && ! str_contains((string) json_encode($context), 'changed');
    })->once();
});

test('a job whose signature does not hold never runs, and is answered so the provider stops delivering it', function (): void {
    $payload = json_decode(queuedMessage(new RememberThatItRan('hello')), true);
    $payload['data']['command'] = serialize(new RememberThatItRan('changed'));

    deliver((string) json_encode($payload))->assertOk()->assertJsonPath('dropped', true);

    // Whatever else is in the payload is covered too, such as the tenant another package adds.
    $payload = json_decode(queuedMessage(new RememberThatItRan('hello')), true);
    $payload['tenant_id'] = 'another-organisation';
    deliver((string) json_encode($payload))->assertOk()->assertJsonPath('dropped', true);

    unset($payload[JobSignature::KEY]);
    deliver((string) json_encode($payload))->assertOk()->assertJsonPath('dropped', true);

    expect(Cache::has('ran'))->toBeFalse();
});

test('anything else posted to the root without a secret still does not exist', function (): void {
    deliver('{"task":"tick"}')->assertNotFound();
    deliver('not even json')->assertNotFound();
});

test('a failing job goes back on its queue counting its attempt, and after its tries it is a failed job', function (): void {
    $message = queuedMessage(new AlwaysFails);

    deliver($message)->assertOk()->assertJsonPath('state', 'released');

    $again = DB::table('jobs')->first();
    $payload = json_decode((string) $again->payload, true);

    expect($again->queue)->toBe('mails')
        ->and($payload[JobSignature::ATTEMPTS])->toBe(1)
        // Signed again, with its attempt and the moment it went back.
        ->and(JobSignature::holdsForApplication($payload))->toBeTrue()
        ->and($payload[JobSignature::SIGNED_AT])->toBeInt();

    DB::table('jobs')->delete();

    // The provider delivers the new message: it runs, though the first one did.
    deliver((string) $again->payload)->assertOk()->assertJsonPath('state', 'failed');

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

test('a delivery that was handled is dropped when it comes again', function (): void {
    $message = queuedMessage(new CountsItsRuns);

    deliver($message)->assertOk()->assertJsonPath('state', 'done');
    deliver($message)->assertOk()->assertJsonPath('dropped', true);
    deliver(reencoded($message))->assertOk()->assertJsonPath('dropped', true);

    expect(Cache::get('runs'))->toBe(1);
});

test('a delivery that is still being handled is answered so the provider tries again, and runs again once that is over', function (): void {
    $message = queuedMessage(new CountsItsRuns);

    // Another request took it and never answered, as when its container stopped.
    expect(Deliveries::claim(json_decode($message, true)))->toBe(Deliveries::RUN);

    deliver($message)->assertStatus(409)->assertExactJson(['ok' => false]);
    expect(Cache::get('runs'))->toBeNull();

    $this->travel((int) config('brewless.ops.time_limit') + 61)->seconds();

    deliver($message)->assertOk()->assertJsonPath('state', 'done');
    expect(Cache::get('runs'))->toBe(1);
});

test('a job signed longer ago than ops.job_max_age is dropped, a younger one runs', function (): void {
    $old = queuedMessage(new CountsItsRuns);
    $this->travel(59)->minutes();
    $young = queuedMessage(new CountsItsRuns);
    $this->travel(2)->minutes();

    deliver($old)->assertOk()->assertJsonPath('dropped', true);
    deliver($young)->assertOk()->assertJsonPath('state', 'done');

    expect(Cache::get('runs'))->toBe(1);
});

test('a job signed in the future is dropped', function (): void {
    $this->travel(5)->minutes();
    $message = queuedMessage(new CountsItsRuns);
    $this->travelBack();

    deliver($message)->assertOk()->assertJsonPath('dropped', true);

    expect(Cache::get('runs'))->toBeNull();
});

test('a job without the moment it was signed, as 0.10 signed it, is dropped', function (): void {
    $payload = json_decode(queuedMessage(new CountsItsRuns), true);
    unset($payload[JobSignature::SIGNED_AT]);

    deliver(signedAgain($payload))->assertOk()->assertJsonPath('dropped', true);

    expect(Cache::get('runs'))->toBeNull();
});

test('the attempts and the moment are signed, so a delivery cannot be made new by changing them', function (): void {
    $payload = json_decode(queuedMessage(new CountsItsRuns), true);

    deliver((string) json_encode([...$payload, JobSignature::ATTEMPTS => 1]))->assertOk()->assertJsonPath('dropped', true);
    deliver((string) json_encode([...$payload, JobSignature::SIGNED_AT => $payload[JobSignature::SIGNED_AT] + 1]))->assertOk()->assertJsonPath('dropped', true);

    expect(Cache::get('runs'))->toBeNull();
});

test('a failed job that is retried long after still runs', function (): void {
    deliver(queuedMessage(new FailsUntilFixed))->assertOk()->assertJsonPath('state', 'failed');

    $this->travel(3)->hours();
    Cache::put('fixed', true);

    $this->postJson('/_ops/queue/retry', [], $this->ops())->assertOk()->assertJsonPath('retried', 1);

    deliver((string) DB::table('jobs')->value('payload'))->assertOk()->assertJsonPath('state', 'done');

    expect(Cache::get('ran'))->toBe('fixed');
});

test('a delivery that breaks is answered without the reason, which is reported instead', function (): void {
    Exceptions::fake();
    // The sign of life after the job cannot be left.
    config(['brewless.ops.heartbeat_store' => 'a-store-that-does-not-exist']);

    deliver(queuedMessage(new CountsItsRuns))->assertOk()->assertExactJson(['ok' => false]);

    Exceptions::assertReported(InvalidArgumentException::class);
});

test('the status names the moments of the schedule, so the scheduler is only called then', function (): void {
    app(Schedule::class)->command('inspire')->everyFifteenMinutes();
    app(Schedule::class)->command('cache:prune-stale-tags')->dailyAt('02:10');

    $moments = $this->getJson('/_ops/status', $this->ops())->assertOk()->json('schedule');

    expect(collect($moments)->pluck('expression')->all())->toContain('*/15 * * * *', '10 2 * * *')
        ->and(collect($moments)->pluck('timezone')->unique()->all())->toBe(['UTC']);
});

test('a container without the ops secret, such as the one that serves visitors, signs its jobs too', function (): void {
    // Brewless gives the ops secret only to the containers that do ops work. The
    // container that serves visitors queues jobs as well (a mail at sign-in),
    // and the worker must be able to run them.
    config(['brewless.ops.secret' => null]);
    $message = queuedMessage(new RememberThatItRan('from a visitor'));
    config(['brewless.ops.secret' => TestCase::OPS_SECRET]);

    expect(json_decode($message, true))->toHaveKey(JobSignature::KEY);

    deliver($message)->assertOk()->assertJsonPath('state', 'done');

    expect(Cache::get('ran'))->toBe('from a visitor');
});

test('a job is not signed with the ops secret, so knowing a job tells nothing about that secret', function (): void {
    $payload = json_decode(queuedMessage(new RememberThatItRan('hello')), true);

    expect(JobSignature::holds($payload, TestCase::OPS_SECRET))->toBeFalse();
});

test('a job signed before the application key was rotated still runs while the old key is a previous key', function (): void {
    $before = (string) config('app.key');
    $message = queuedMessage(new RememberThatItRan('before the rotation'));

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$before]]);

    deliver($message)->assertOk()->assertJsonPath('state', 'done');

    expect(Cache::get('ran'))->toBe('before the rotation');
});

test('a job signed under another application key is dropped', function (): void {
    $message = queuedMessage(new RememberThatItRan('elsewhere'));

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => []]);

    deliver($message)->assertOk()->assertJsonPath('dropped', true);

    expect(Cache::get('ran'))->toBeNull();
});

test('a signed job runs from an address that guessed the ops secret wrong too often', function (): void {
    foreach (range(1, 15) as $guess) {
        $this->getJson('/_ops/status', ['X-Brewless-Ops' => 'guess-'.$guess])->assertNotFound();
    }

    deliver(queuedMessage(new RememberThatItRan('still delivered')))->assertOk()->assertJsonPath('state', 'done');

    expect(Cache::get('ran'))->toBe('still delivered');
});
