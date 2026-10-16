<?php

declare(strict_types=1);

namespace Brewless\Laravel\Ops;

use Brewless\Laravel\Queue\JobSignature;
use Brewless\Laravel\Queue\TriggeredJob;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * What Brewless asks of an application's own containers: run the release
 * commands, tick the scheduler, work the queue, say how both are doing, and
 * run one command. Everything runs here, in the application, with its own
 * database connection; Brewless only asks and reads the answer.
 */
final class Runtime
{
    private const int OUTPUT_LIMIT = 100_000;

    /** Above what a container can hold, so the worker's memory guard never cuts a tick short. */
    private const int MEMORY_MB = 4096;

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
     * Run the release commands of the project's brewless.yml, in order. One
     * that never ends or throws data away is refused before any runs; one
     * that fails stops the release. The answer names each command with what
     * it printed under `commands`, which is how Brewless knows these ran and
     * not the application's own.
     *
     * @param  list<string>  $commands
     * @return array{commands: array<string, string>, seconds: float}
     */
    public function releaseCommands(array $commands): array
    {
        foreach ($commands as $command) {
            $name = strtok(trim($command), ' ') ?: '';

            if (in_array($name, (array) config('brewless.ops.never_ending'), true) || in_array($name, (array) config('brewless.ops.destructive'), true)) {
                throw new RuntimeException('Release command "'.$name.'" is refused: it never ends or throws data away.');
            }
        }

        $started = microtime(true);
        $printed = [];

        foreach ($commands as $command) {
            $exitCode = Artisan::call(trim($command).' --no-interaction');
            $printed[trim($command)] = Str::limit(Artisan::output(), self::OUTPUT_LIMIT);

            if ($exitCode !== 0) {
                throw new RuntimeException('Release command "'.trim($command).'" ended with exit code '.$exitCode.'.');
            }
        }

        return ['commands' => $printed, 'seconds' => round(microtime(true) - $started, 2)];
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
     * Work the queue until it is empty or the time is up: the named queues,
     * the first first, or the application's default.
     *
     * @param  list<string>|null  $queues
     * @return array{output: string, seconds: float}
     */
    public function queue(?array $queues = null): array
    {
        $started = microtime(true);

        // The queue was looked at; that is the sign of life, whether or not there was work.
        Heartbeat::beat(Heartbeat::QUEUE);

        // Nothing due: do not start a worker. Starting one costs a dozen
        // queries on the cache and jobs tables just to find the queue empty.
        if (! $this->hasWork($queues)) {
            return ['output' => '', 'seconds' => round(microtime(true) - $started, 2)];
        }

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => (int) config('brewless.ops.queue_seconds'),
            '--tries' => (int) config('brewless.ops.queue_tries'),
            // The worker stops after a job once the process is over its memory limit,
            // and would leave the rest of the queue waiting. That guard is for a
            // daemon that never restarts; this one ends with the request anyway.
            '--memory' => self::MEMORY_MB,
            '--no-interaction' => true,
            ...($queues === null ? [] : ['--queue' => implode(',', $queues)]),
        ]);

        return ['output' => Artisan::output(), 'seconds' => round(microtime(true) - $started, 2)];
    }

    /**
     * Run one job a queue trigger of the provider delivered, the way a queue
     * worker would: its tries, its backoff, its `failed()` and the failed
     * jobs table all apply. A job that is tried again goes back on its queue
     * as a new message; the provider takes this one off once we answer.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{job: string, state: string, seconds: float}
     */
    public function job(string $raw, array $payload): array
    {
        $started = microtime(true);
        $connection = (string) config('queue.default');
        $queue = $payload[JobSignature::QUEUE] ?? null;
        $job = new TriggeredJob(Container::getInstance(), $raw, $payload, $connection, is_string($queue) ? $queue : null);

        try {
            app('queue.worker')->process($connection, $job, new WorkerOptions(
                backoff: 0,
                memory: self::MEMORY_MB,
                maxTries: (int) config('brewless.ops.queue_tries'),
            ));
        } catch (Throwable $exception) {
            // The worker released or failed the job already, and rethrows as a
            // daemon would for its log. Reported the same way here; a job that
            // failed for good is kept where `queue:work` keeps it.
            report($exception);

            if ($job->hasFailed()) {
                app('queue.failer')->log($connection, (string) $job->getQueue(), $raw, $exception);
            }
        }

        Heartbeat::beat(Heartbeat::QUEUE);

        return [
            'job' => $job->resolveName(),
            'state' => $job->hasFailed() ? 'failed' : ($job->isReleased() ? 'released' : 'done'),
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * The moments the application's schedule has work, as cron expressions
     * with their time zone: so whoever calls the scheduler can call it only
     * then, instead of every minute.
     *
     * @return list<array{expression: string, timezone: string, task: string}>
     */
    public function scheduledMoments(): array
    {
        // The schedule is defined with the console's routes, which a web request does not load by itself.
        app(ConsoleKernel::class)->bootstrap();

        $moments = [];

        foreach (app(Schedule::class)->events() as $event) {
            $timezone = $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : (string) ($event->timezone ?? config('app.timezone'));

            $moments[] = ['expression' => $event->expression, 'timezone' => $timezone, 'task' => $event->getSummaryForDisplay()];
        }

        return $moments;
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
            'schedule' => $this->scheduledMoments(),
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
     * Run one console command. It never asks a question; one that never ends
     * by itself and one that throws data away are refused.
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

        if (in_array($name, (array) config('brewless.ops.destructive'), true)) {
            return ['exit_code' => 1, 'output' => 'Command "'.$name.'" throws data away and does not run over HTTP. Run it at the console, on purpose.', 'seconds' => 0.0, 'refused' => true];
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

    /**
     * Is there a job to run now? Only the database queue can be asked this
     * cheaply; with any other queue the worker is started and finds out.
     */
    /**
     * @param  list<string>|null  $queues
     */
    private function hasWork(?array $queues = null): bool
    {
        $connection = 'queue.connections.'.config('queue.default');

        if (config($connection.'.driver') !== 'database') {
            return true;
        }

        return DB::connection(config($connection.'.connection'))
            ->table((string) config($connection.'.table', 'jobs'))
            ->where('available_at', '<=', now()->getTimestamp())
            ->when($queues !== null, fn ($query) => $query->whereIn('queue', $queues))
            ->exists();
    }
}
