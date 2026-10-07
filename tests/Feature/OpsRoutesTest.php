<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Ops\Heartbeat;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestCase::class);

/**
 * A failed job as the queue leaves it behind.
 */
function failedJob(string $name): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $name, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => ['password' => 'do-not-show'], 'attempts' => 0]),
        'exception' => 'RuntimeException: card 4111 was declined',
        'failed_at' => now(),
    ]);

    return $uuid;
}

test('without the secret none of the routes exist', function (string $method, string $path): void {
    $this->json($method, $path)->assertNotFound();
    $this->json($method, $path, [], ['X-Brewless-Ops' => 'wrong'])->assertNotFound();
})->with([
    ['POST', '/_ops/migrate'],
    ['POST', '/_ops/schedule'],
    ['POST', '/_ops/queue'],
    ['POST', '/_ops/queue/retry'],
    ['POST', '/_ops/command'],
    ['GET', '/_ops/status'],
    ['POST', '/'],
]);

test('a container that was given no secret has no ops routes at all', function (): void {
    config(['brewless.ops.secret' => null]);

    $this->postJson('/_ops/migrate', [], ['X-Brewless-Ops' => ''])->assertNotFound();
    $this->postJson('/', ['secret' => '', 'task' => 'tick'])->assertNotFound();
});

test('a release runs the release commands and answers with what they printed', function (): void {
    $this->postJson('/_ops/migrate', [], $this->ops())
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure(['migrate', 'seconds']);
});

test('a release command that fails answers 500 with the reason, so the release does not go live', function (): void {
    config(['brewless.ops.release_commands' => ['migrate --force', 'this:does-not-exist']]);

    $this->postJson('/_ops/migrate', [], $this->ops())
        ->assertStatus(500)
        ->assertJsonPath('ok', false)
        ->assertJsonStructure(['error']);
});

test('a tick plans and works, and leaves a sign of life outside the database', function (): void {
    $this->postJson('/', ['secret' => TestCase::OPS_SECRET, 'task' => 'tick'])
        ->assertOk()
        ->assertJsonStructure(['schedule' => ['output', 'seconds'], 'queue' => ['output', 'seconds']]);

    expect(Heartbeat::last(Heartbeat::SCHEDULE))->not->toBeNull()
        ->and(Heartbeat::last(Heartbeat::QUEUE))->not->toBeNull();

    $this->postJson('/', ['secret' => TestCase::OPS_SECRET, 'task' => 'something-else'])->assertUnprocessable();
});

test('the status says how the schedule and the queue are doing, and which jobs failed but not why', function (): void {
    $uuid = failedJob('App\Jobs\ChargeCard');
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);

    $response = $this->getJson('/_ops/status', $this->ops())
        ->assertOk()
        ->assertJsonPath('release', 'registry.example/shop:abc123')
        ->assertJsonPath('jobs_waiting', 1)
        ->assertJsonPath('jobs_failed', 1)
        ->assertJsonPath('failed.0.id', $uuid)
        ->assertJsonPath('failed.0.name', 'App\Jobs\ChargeCard')
        ->assertJsonPath('schedule_heartbeat', null);

    expect($response->getContent())->not->toContain('4111')->not->toContain('do-not-show');
});

test('failed jobs go back on the queue', function (): void {
    failedJob('App\Jobs\ChargeCard');
    failedJob('App\Jobs\SendReceipt');

    $this->postJson('/_ops/queue/retry', [], $this->ops())->assertOk()->assertJsonPath('retried', 2);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(2);
});

test('one command runs and answers with its output and exit code', function (): void {
    Artisan::command('shop:greet {name}', function (string $name): int {
        $this->line('Hello '.$name);

        return 3;
    });

    $this->postJson('/_ops/command', ['command' => 'shop:greet Ada'], $this->ops())
        ->assertOk()
        ->assertJsonPath('exit_code', 3)
        ->assertJsonPath('refused', false)
        ->assertJsonPath('output', "Hello Ada\n");
});

test('a command that never ends, is unknown or is not one line does not run', function (): void {
    $this->postJson('/_ops/command', ['command' => 'queue:work --daemon'], $this->ops())->assertOk()->assertJsonPath('refused', true);
    $this->postJson('/_ops/command', ['command' => 'nope:nothing'], $this->ops())->assertOk()->assertJsonPath('exit_code', 1)->assertJsonPath('refused', false);
    $this->postJson('/_ops/command', ['command' => "about\nmigrate:fresh"], $this->ops())->assertUnprocessable();
    $this->postJson('/_ops/command', [], $this->ops())->assertUnprocessable();
});

test('an address that keeps guessing the secret is shut out, with the same 404', function (): void {
    foreach (range(1, 10) as $guess) {
        $this->getJson('/_ops/status', ['X-Brewless-Ops' => 'guess-'.$guess])->assertNotFound();
    }

    // The right secret no longer helps this address for a minute; another address is unaffected.
    $this->getJson('/_ops/status', $this->ops())->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/_ops/status', $this->ops())->assertOk();

    $this->travel(61)->seconds();
    $this->withServerVariables([])->getJson('/_ops/status', $this->ops())->assertOk();
});

test('a command that throws data away does not run over http', function (): void {
    foreach (['db:wipe --force', 'migrate:fresh --force', 'migrate:reset', 'migrate:rollback --step=1'] as $destructive) {
        $this->postJson('/_ops/command', ['command' => $destructive], $this->ops())
            ->assertOk()
            ->assertJsonPath('refused', true)
            ->assertJsonPath('exit_code', 1)
            ->assertSee('throws data away');
    }

    config(['brewless.ops.destructive' => ['shop:purge']]);

    $this->postJson('/_ops/command', ['command' => 'shop:purge'], $this->ops())->assertJsonPath('refused', true);
});

test('a job that is due is worked in the tick, one that is not due does not start a worker', function (): void {
    dispatch(function (): void {
        Cache::put('ran-too-early', true);
    })->delay(now()->addMinutes(10));

    $this->postJson('/', ['secret' => TestCase::OPS_SECRET, 'task' => 'tick'])->assertOk()->assertJsonPath('queue.output', '');

    expect(Cache::get('ran-too-early'))->toBeNull();

    $this->travel(11)->minutes();
    $this->postJson('/', ['secret' => TestCase::OPS_SECRET, 'task' => 'tick'])->assertOk();

    expect(Cache::get('ran-too-early'))->toBeTrue();
});

test('an application names only the settings it changes', function (): void {
    config(['brewless.ops' => ['release_commands' => ['migrate --force', 'shop:warm']]]);

    (new BrewlessServiceProvider($this->app))->register();

    expect(config('brewless.ops.release_commands'))->toBe(['migrate --force', 'shop:warm'])
        ->and(config('brewless.ops.secret_header'))->toBe('X-Brewless-Ops')
        ->and(config('brewless.ops.never_ending'))->toContain('tinker')
        ->and(config('brewless.edge.host_header'))->toBe('Cdn-Host');
});
