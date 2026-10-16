<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;

/**
 * Laravel's own database queue, with every job signed (SignsJobs).
 */
final class SignedDatabaseConnector extends DatabaseConnector
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): SignedDatabaseQueue
    {
        return new SignedDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        );
    }
}
