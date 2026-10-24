<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

use function hash;
use function is_string;

/**
 * Runs each delivery of a signed job once.
 *
 * A delivery is one signed message: the job, its attempts and the moment it
 * was signed. The provider delivering it again, or someone who saw it
 * posting it again, sends the same signature; a job that goes back on its
 * queue, or a failed job that is retried, is signed again and is a delivery
 * of its own. So the signature names the delivery.
 *
 * Its mark is taken atomically before the job runs (`running`, for as long as
 * a request may take) and becomes `done` afterwards, for as long as the
 * signature is good. A delivery whose request never answered (its container
 * stopped) is run again once the `running` mark has expired; until then the
 * provider is asked to try again later.
 *
 * The marks are kept in the application's cache, which every container of
 * the application must share for a delivery to another container to be
 * recognised. When that cache cannot be reached, a delivery runs: the
 * signature and its moment still hold, and dropping every job would lose them.
 */
final class Deliveries
{
    /** Not seen before: run it. */
    public const string RUN = 'run';

    /** Handled already: drop it. */
    public const string DONE = 'done';

    /** Being handled by another request: let the provider try again later. */
    public const string BUSY = 'busy';

    /**
     * Take the mark of a delivery, and say what to do with it.
     *
     * @param  array<array-key, mixed>  $payload
     * @return self::RUN|self::DONE|self::BUSY
     */
    public static function claim(array $payload): string
    {
        $key = self::key($payload);

        try {
            if (Cache::add($key, self::BUSY, self::runningSeconds())) {
                return self::RUN;
            }

            return Cache::get($key) === self::DONE ? self::DONE : self::BUSY;
        } catch (Throwable $exception) {
            Log::warning('brewless: the cache could not be reached, so a queue message runs without checking whether it ran before', [
                'uuid' => $payload['uuid'] ?? null,
                'exception' => $exception::class,
            ]);

            return self::RUN;
        }
    }

    /**
     * Mark a delivery as handled, whatever became of its job.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function finish(array $payload): void
    {
        try {
            Cache::put(self::key($payload), self::DONE, JobSignature::maxAge() + JobSignature::CLOCK_SKEW + 60);
        } catch (Throwable) {
            // Its moment still runs out.
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function key(array $payload): string
    {
        $signature = $payload[JobSignature::KEY] ?? null;

        return 'brewless-delivery:'.hash('sha256', is_string($signature) ? $signature : '');
    }

    /** As long as an ops request may run, and a minute. */
    private static function runningSeconds(): int
    {
        return max((int) config('brewless.ops.time_limit'), 30) + 60;
    }
}
