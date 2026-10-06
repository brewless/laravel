<?php

declare(strict_types=1);

namespace Brewless\Laravel\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What Brewless asks of an application's own containers: run the release
 * commands, tick the scheduler, work the queue, say how both are doing, and
 * run one command. Everything runs here, in the application, with its own
 * database connection; Brewless only asks and reads the answer.
 */
final class Runtime
{
    private const int OUTPUT_LIMIT = 100_000;

    /**
     * Run the release commands in order. An exception stops the release.
     *
     * @return array<string, string|float>
     */
    public function release(): array
    {
        $started = microtime(true);
        $printed = [];

        foreach ((array) config('brewless.ops.release_commands') as $command) {
            Artisan::call((string) $command.' --no-interaction');
            $printed[strtok((string) $command, ' ') ?: 'command'] = Artisan::output();
        }

        return [...$printed, 'seconds' => round(microtime(true) - $started, 2)];
    }

    /**
     * One tick of the scheduler: what `schedule:run` does every minute on a server.
     *
     * @return array{output: string, seconds: float}
     */
    public function schedule(): array
    {
        $started = microtime(true);

        Artisan::call('schedule:run', ['--no-interaction' => true]);
        Heartbeat::beat(Heartbeat::SCHEDULE);

        return ['output' => Artisan::output(), 'seconds' => round(microtime(true) - $started, 2)];
    }

    /**
     * Work the queue until it is empty or the time is up.
     *
     * @return array{output: string, seconds: float}
     */
    public function queue(): array
    {
        $started = microtime(true);

        // The queue was looked at; that is the sign of life, whether or not there was work.
        Heartbeat::beat(Heartbeat::QUEUE);

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => (int) config('brewless.ops.queue_seconds'),
            '--tries' => (int) config('brewless.ops.queue_tries'),
            '--no-interaction' => true,
        ]);

        return ['output' => Artisan::output(), 'seconds' => round(microtime(true) - $started, 2)];
    }

    /**
     * How the schedule and the queue are doing. Which jobs failed, not why:
     * an exception can quote the data it failed on.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $failed = [];
        $waiting = null;
        $failedCount = null;

        // Only the database queue can be counted this cheaply; with another queue the counts are left out.
        if (config('queue.connections.'.config('queue.default').'.driver') === 'database') {
            $waiting = DB::table((string) config('queue.connections.'.config('queue.default').'.table', 'jobs'))->count();
        }

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $table = (string) config('queue.failed.table', 'failed_jobs');
            $failedCount = DB::table($table)->count();

            foreach (DB::table($table)->latest('failed_at')->limit(20)->get(['uuid', 'queue', 'payload', 'failed_at']) as $job) {
                $payload = json_decode((string) $job->payload, true);

                $failed[] = [
                    'id' => (string) $job->uuid,
                    'name' => is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : null,
                    'queue' => (string) $job->queue,
                    'failed_at' => Carbon::parse((string) $job->failed_at)->toIso8601String(),
                ];
            }
        }

        return [
            'release' => config('brewless.release'),
            'schedule_heartbeat' => Heartbeat::last(Heartbeat::SCHEDULE),
            'queue_heartbeat' => Heartbeat::last(Heartbeat::QUEUE),
            'jobs_waiting' => $waiting ?? 0,
            'jobs_failed' => $failedCount ?? 0,
            'failed' => $failed,
            'now' => now()->toIso8601String(),
        ];
    }

    /**
     * Put every failed job back on the queue.
     *
     * @return array{retried: int}
     */
    public function retryFailed(): array
    {
        $failed = (int) ($this->status()['jobs_failed'] ?? 0);

        if ($failed > 0) {
            Artisan::call('queue:retry', ['id' => ['all'], '--no-interaction' => true]);
        }

        return ['retried' => $failed];
    }

    /**
     * Run one console command. It never asks a question, and one that never
     * ends by itself is refused.
     *
     * @return array{exit_code: int, output: string, seconds: float, refused: bool}
     */
    public function command(string $command): array
    {
        $started = microtime(true);
        $name = strtok(trim($command), ' ') ?: '';

        if ($name === '' || in_array($name, (array) config('brewless.ops.never_ending'), true)) {
            return ['exit_code' => 1, 'output' => '', 'seconds' => 0.0, 'refused' => true];
        }

        if (! array_key_exists($name, Artisan::all())) {
            return ['exit_code' => 1, 'output' => 'Command "'.$name.'" is not defined.', 'seconds' => 0.0, 'refused' => false];
        }

        $exitCode = Artisan::call($command.' --no-interaction');

        return [
            'exit_code' => $exitCode,
            'output' => Str::limit(Artisan::output(), self::OUTPUT_LIMIT),
            'seconds' => round(microtime(true) - $started, 2),
            'refused' => false,
        ];
    }
}
