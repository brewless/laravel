<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use function is_string;

/**
 * Signs the payload of every job a queue takes, once it is final: after the
 * command was serialised, which is what a trigger would unserialise.
 */
trait SignsJobs
{
    /**
     * @param  object|string  $job
     * @param  string|null  $queue
     * @param  mixed  $data
     * @return array<array-key, mixed>
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        $payload = parent::createPayloadArray($job, $queue, $data);
        $secret = config('brewless.ops.secret');

        if (! is_string($secret) || $secret === '') {
            return $payload;
        }

        $payload[JobSignature::QUEUE] = $queue;

        return [...$payload, JobSignature::KEY => JobSignature::sign($payload, $secret)];
    }
}
