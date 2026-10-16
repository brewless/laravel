<?php

declare(strict_types=1);

use Brewless\Laravel\Queue\JobSignature;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        ->and(JobSignature::holds($payload, TestCase::OPS_SECRET))->toBeTrue()
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
        // Still signed: the attempts are not part of what is signed.
        ->and(JobSignature::holds($payload, TestCase::OPS_SECRET))->toBeTrue();

    DB::table('jobs')->delete();

    deliver((string) $again->payload)->assertOk()->assertJsonPath('state', 'failed');

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

test('the status names the moments of the schedule, so the scheduler is only called then', function (): void {
    app(Schedule::class)->command('inspire')->everyFifteenMinutes();
    app(Schedule::class)->command('cache:prune-stale-tags')->dailyAt('02:10');

    $moments = $this->getJson('/_ops/status', $this->ops())->assertOk()->json('schedule');

    expect(collect($moments)->pluck('expression')->all())->toContain('*/15 * * * *', '10 2 * * *')
        ->and(collect($moments)->pluck('timezone')->unique()->all())->toBe(['UTC']);
});
