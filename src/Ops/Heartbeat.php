<?php

declare(strict_types=1);

namespace Brewless\Laravel\Ops;

use Illuminate\Support\Facades\Cache;

/**
 * When the scheduler last ticked and the queue was last looked at. Kept
 * outside the database on purpose: it is written every minute, and a tick
 * that finds nothing to do should not touch the database to say so.
 */
final class Heartbeat
{
    public const string SCHEDULE = 'brewless:heartbeat:schedule';

    public const string QUEUE = 'brewless:heartbeat:queue';

    public static function beat(string $name): void
    {
        Cache::store((string) config('brewless.ops.heartbeat_store'))->forever($name, now()->toIso8601String());
    }

    public static function last(string $name): ?string
    {
        $moment = Cache::store((string) config('brewless.ops.heartbeat_store'))->get($name);

        return is_string($moment) ? $moment : null;
    }
}
