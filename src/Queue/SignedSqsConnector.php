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
        $config = $this->getDefaultConfiguration($config);

        // Laravel 13 sets the credentials in withCredentials(), with its credential cache; Laravel 12 inline.
        if (method_exists($this, 'withCredentials')) {
            $config = $this->withCredentials($config);
        } elseif ($credentials = $this->resolveCredentialProvider($config)) {
            $config['credentials'] = $credentials;
        } elseif (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);

            if (! empty($config['token'])) {
                $config['credentials']['token'] = $config['token'];
            }
        }

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
