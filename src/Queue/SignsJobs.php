<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

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
        $key = JobSignature::key();

        if ($key === null) {
            return $payload;
        }

        $payload[JobSignature::QUEUE] = $queue;

        return [...$payload, JobSignature::KEY => JobSignature::sign($payload, $key)];
    }
}
