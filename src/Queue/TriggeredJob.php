<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

use function is_int;
use function is_string;
use function json_encode;
use function max;
use function min;

/**
 * A job the provider's queue trigger delivered in a request. The provider
 * takes the message off the queue once the request answers, so this job is
 * never left on the queue: done is done, and a job that is tried again is
 * put back as a new message that counts its attempts.
 */
final class TriggeredJob extends Job implements JobContract
{
    /** The longest delay a queue at the provider takes on a message, in seconds. */
    private const int MAX_DELAY = 900;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(Container $container, private readonly string $raw, private readonly array $payload, string $connectionName, ?string $queue)
    {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): ?string
    {
        return is_string($this->payload['uuid'] ?? null) ? $this->payload['uuid'] : null;
    }

    public function getRawBody(): string
    {
        return $this->raw;
    }

    public function attempts(): int
    {
        $before = $this->payload[JobSignature::ATTEMPTS] ?? 0;

        return (is_int($before) ? max($before, 0) : 0) + 1;
    }

    /**
     * Put the job back as a new message, one attempt further, after the delay,
     * signed again: the attempts and the moment are part of what is signed.
     *
     * @param  int  $delay
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->container['queue']->connection($this->connectionName)->pushRaw(
            (string) json_encode(JobSignature::resign([...$this->payload, JobSignature::ATTEMPTS => $this->attempts()])),
            $this->queue,
            $delay > 0 ? ['DelaySeconds' => min((int) $delay, self::MAX_DELAY)] : [],
        );
    }
}
