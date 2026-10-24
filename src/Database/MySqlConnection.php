<?php

declare(strict_types=1);

namespace Brewless\Laravel\Database;

use Illuminate\Database\MySqlConnection as LaravelMySqlConnection;

/**
 * Laravel's MySQL connection, with a lost connection tried again for a while.
 */
final class MySqlConnection extends LaravelMySqlConnection
{
    use RetriesLostConnections;
}
