<?php

declare(strict_types=1);

namespace Brewless\Laravel\Database;

use Illuminate\Database\PostgresConnection as LaravelPostgresConnection;

/**
 * Laravel's PostgreSQL connection, with a lost connection tried again for a while.
 */
final class PostgresConnection extends LaravelPostgresConnection
{
    use RetriesLostConnections;
}
