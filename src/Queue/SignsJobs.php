<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

/**
 * Signs the payload of every job a queue takes, once it is final: after the
 * command was serialised, which is what a trigger would unserialise. A job of
 * this application that is pushed again as it is gets a new moment.
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

        return JobSignature::stamp($payload, $key);
    }

    /**
     * A job of this application that is put on the queue as it is, such as a
     * failed job that is retried, is signed again for now: its first moment may
     * be longer ago than a signed job is good for. Only one whose signature
     * holds; anything else goes on as it came.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  array<array-key, mixed>  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded) && JobSignature::isJob($decoded) && JobSignature::holdsForApplication($decoded)) {
            $payload = (string) json_encode(JobSignature::resign($decoded));
        }

        return parent::pushRaw($payload, $queue, $options);
    }
}
