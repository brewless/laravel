<?php

declare(strict_types=1);

namespace Brewless\Laravel\Database;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * A query whose connection went away is tried again for a while, not once.
 *
 * Laravel reconnects and runs such a query once, at once. While a managed
 * database is restarted for maintenance (Scaleway's Serverless SQL Database
 * disconnects its clients for at most a minute, by default on Tuesdays between
 * 14:30 and 14:45 CEST) that one try comes too early and the request fails.
 * The same holds for a container that just started from zero: its private
 * network can take a few seconds to carry traffic, and until then connecting
 * fails with "No route to host" (seen on Scaleway Serverless Containers on
 * 29 October 2026, five seconds after a cold start). And a Serverless SQL
 * Database that slept wakes up when it is connected to; until it is up,
 * connecting ends in "timeout expired" (seen on 29 October 2026, when a
 * release's migrations were the first to reach the databases that morning).
 * Here the query is tried again with pauses of 1, 2, 4 and then 8 seconds,
 * until it runs or `brewless.database.retry_seconds` have passed.
 *
 * As in Laravel, never inside a transaction: what ran before in it is gone
 * with the connection, so trying the last query again would be wrong.
 */
trait RetriesLostConnections
{
    /**
     * What PostgreSQL says while it shuts down or starts up, and what
     * connecting says while the network to the database is not there yet, on
     * top of what Laravel already counts as a lost connection. Laravel knows
     * "No route to host" only after an SSL error, not when connecting fails.
     */
    private const array GOING_AWAY = [
        'terminating connection due to administrator command',
        'the database system is shutting down',
        'the database system is starting up',
        'the database system is in recovery mode',
        'SQLSTATE[57P01]',
        'SQLSTATE[57P02]',
        'SQLSTATE[57P03]',
        'failed: No route to host',
        'failed: Network is unreachable',
        '[2002] No route to host',
        '[2002] Network is unreachable',
        'failed: timeout expired',
    ];

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, Closure $callback): mixed
    {
        $seconds = (int) config('brewless.database.retry_seconds', 60);
        $waited = 0;
        $pause = 0;

        // Never a pause that would end past the limit: then the error is given as it is.
        while ($this->lostWith($e) && $waited + $pause <= $seconds) {
            if ($pause > 0) {
                Sleep::for($pause)->seconds();
                $waited += $pause;
            }

            // The first try is at once, as Laravel's own; after that 1, 2, 4 and then 8 seconds apart.
            $pause = $pause === 0 ? 1 : min($pause * 2, 8);

            try {
                $this->reconnect();

                $result = $this->runQueryCallback($query, $bindings, $callback);

                if ($waited > 0) {
                    Log::info('The database answered again after a lost connection.', ['connection' => $this->getName(), 'seconds' => $waited]);
                }

                return $result;
            } catch (QueryException $next) {
                $e = $next;
            } catch (Throwable $next) {
                // Reconnecting itself can fail the same way; a reason of another kind ends the tries.
                if (! $this->causedByLostConnection($next) && ! $this->goingAway($next)) {
                    throw $next;
                }
            }

            if ($waited === 0) {
                Log::warning('The database connection was lost; trying the query again for a while.', ['connection' => $this->getName(), 'limit' => $seconds]);
            }
        }

        throw $e;
    }

    private function lostWith(QueryException $e): bool
    {
        $reason = $e->getPrevious() ?? $e;

        return $this->causedByLostConnection($reason) || $this->goingAway($reason);
    }

    private function goingAway(Throwable $e): bool
    {
        foreach (self::GOING_AWAY as $message) {
            if (str_contains($e->getMessage(), $message)) {
                return true;
            }
        }

        return false;
    }
}
