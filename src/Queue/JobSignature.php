<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use function hash_equals;
use function hash_hmac;
use function implode;
use function is_array;
use function is_string;
use function json_encode;

/**
 * A job this application put on its queue carries a signature made with the
 * ops secret. A trigger of the provider delivers a queue message to the
 * root of the container without any secret of ours, and a job is code that
 * gets unserialised: only a message whose signature holds is ever run.
 *
 * The signature covers what decides what runs: the job's identifier, its
 * handler and its data. The attempts are counted next to it and are not
 * covered: changing them only changes when a job is given up.
 */
final class JobSignature
{
    public const string KEY = 'brewless_signature';

    /** The queue the job was put on, so a job that is tried again goes back to the same one. */
    public const string QUEUE = 'brewless_queue';

    /** How often a job was tried before, counted here because the provider's trigger does not say. */
    public const string ATTEMPTS = 'brewless_attempts';

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function sign(array $payload, string $secret): string
    {
        return hash_hmac('sha256', self::subject($payload), $secret);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function holds(array $payload, string $secret): bool
    {
        $given = $payload[self::KEY] ?? null;

        return $secret !== '' && is_string($given) && hash_equals(self::sign($payload, $secret), $given);
    }

    /**
     * Whether a message looks like a job of a Laravel queue at all.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function isJob(array $payload): bool
    {
        return is_string($payload['uuid'] ?? null) && is_string($payload['job'] ?? null) && is_array($payload['data'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function subject(array $payload): string
    {
        return implode("\n", [
            is_string($payload['uuid'] ?? null) ? $payload['uuid'] : '',
            is_string($payload['job'] ?? null) ? $payload['job'] : '',
            (string) json_encode($payload['data'] ?? null),
        ]);
    }
}
