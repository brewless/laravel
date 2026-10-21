<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use function array_is_list;
use function array_map;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;

/**
 * A job this application put on its queue carries a signature made with the
 * ops secret. A trigger of the provider delivers a queue message to the
 * root of the container without any secret of ours, and a job is code that
 * gets unserialised: only a message whose signature holds is ever run.
 *
 * The signature covers the whole payload: the job's identifier, its
 * handler, its data and whatever other packages add, such as the tenant it
 * runs for. The attempts are counted next to it and are not covered:
 * changing them only changes when a job is given up.
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
     * The whole payload as it was signed: everything but the signature, the
     * attempts counted next to it, and the delay the queue adds after it.
     * Hooks of other packages add to the payload too (a tenant, for
     * instance); they are covered as well.
     *
     * What is signed is the content, not the way it was written: the keys
     * of every object are sorted and slashes and other characters written
     * one way. A queue of the provider may write the message again with
     * another JSON encoder on its way to the container, with the same
     * content in another order or another escaping.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function subject(array $payload): string
    {
        unset($payload[self::KEY], $payload[self::ATTEMPTS], $payload['delay']);

        $content = json_decode((string) json_encode($payload), true);

        return (string) json_encode(self::sorted($content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The same value with the keys of every object in order; a list keeps its order.
     */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
