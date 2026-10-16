<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Support\Arr;

/**
 * Laravel's own SQS connection, with every job signed (SignsJobs).
 */
final class SignedSqsConnector extends SqsConnector
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): SignedSqsQueue
    {
        $config = $this->withCredentials($this->getDefaultConfiguration($config));

        return new SignedSqsQueue(
            new SqsClient(Arr::except($config, ['token', 'overflow', 'credential_cache'])),
            $config['queue'],
            $config['prefix'] ?? '',
            $config['suffix'] ?? '',
            $config['after_commit'] ?? null,
            $config['overflow'] ?? [],
        );
    }
}
