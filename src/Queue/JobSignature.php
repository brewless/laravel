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
use function max;
use function now;

/**
 * A job this application put on its queue carries a signature made with a key
 * derived from the application key (APP_KEY). A trigger of the provider delivers a queue message to the
 * root of the container without any secret of ours, and a job is code that
 * gets unserialised: only a message whose signature holds is ever run.
 *
 * The signature covers the whole payload: the job's identifier, its
 * handler, its data and whatever other packages add, such as the tenant it
 * runs for, the attempts counted before, and the moment it was signed. A job
 * that goes back on its queue is signed again, with its new attempt and a new
 * moment. So a delivery is only good for `ops.job_max_age` seconds, and within
 * that time Deliveries runs each one once.
 */
final class JobSignature
{
    /**
     * What the application key is mixed with, so the signing key is a key of its
     * own: knowing a signature or the key tells nothing about the other uses of
     * the application key, or about the ops secret.
     */
    private const string PURPOSE = 'brewless/queue-signature';

    public const string KEY = 'brewless_signature';

    /** The queue the job was put on, so a job that is tried again goes back to the same one. */
    public const string QUEUE = 'brewless_queue';

    /** How often a job was tried before, counted here because the provider's trigger does not say. */
    public const string ATTEMPTS = 'brewless_attempts';

    /** When the job was signed, in seconds since the epoch: when it was queued, or queued again. */
    public const string SIGNED_AT = 'brewless_signed_at';

    /** How far a signing moment may lie ahead of this container's clock, in seconds. */
    public const int CLOCK_SKEW = 60;

    /**
     * Mark a payload with the moment and sign it with the key given.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function stamp(array $payload, string $key): array
    {
        unset($payload[self::KEY]);
        $payload[self::SIGNED_AT] = now()->getTimestamp();

        return [...$payload, self::KEY => self::sign($payload, $key)];
    }

    /**
     * Sign a job of this application again with the application key, for now:
     * one that goes back on its queue, or a failed job that is retried. Without
     * an application key it is left as it is, and dropped where it arrives.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function resign(array $payload): array
    {
        $key = self::key();

        return $key === null ? $payload : self::stamp($payload, $key);
    }

    /**
     * Whether the job was signed within `ops.job_max_age` seconds, and not
     * later than a minute from now. A job without the moment, as 0.10 and
     * earlier signed it, is not.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function isFresh(array $payload): bool
    {
        $signedAt = $payload[self::SIGNED_AT] ?? null;

        if (! is_int($signedAt)) {
            return false;
        }

        $now = now()->getTimestamp();

        return $signedAt <= $now + self::CLOCK_SKEW && $now - $signedAt <= self::maxAge();
    }

    /** How long a signed job is good for, in seconds. */
    public static function maxAge(): int
    {
        return max((int) config('brewless.ops.job_max_age'), 1);
    }

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
     * The key every container of the application signs its jobs with.
     *
     * Not the ops secret: Brewless gives that only to the containers that do ops
     * work, and the container that serves visitors queues jobs too (a mail at
     * sign-in). The application key is the one secret every container of an
     * application has. Null without one: then no job is signed.
     */
    public static function key(): ?string
    {
        return self::derive(config('app.key'));
    }

    /**
     * Whether the signature of a job holds under the application key, or under
     * one of its previous keys after a rotation (`app.previous_keys`).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function holdsForApplication(array $payload): bool
    {
        $keys = [config('app.key'), ...(array) config('app.previous_keys', [])];

        foreach ($keys as $key) {
            $derived = self::derive($key);

            if ($derived !== null && self::holds($payload, $derived)) {
                return true;
            }
        }

        return false;
    }

    private static function derive(mixed $applicationKey): ?string
    {
        if (! is_string($applicationKey) || $applicationKey === '') {
            return null;
        }

        return hash_hmac('sha256', self::PURPOSE, $applicationKey);
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
     * The whole payload as it was signed: everything but the signature and
     * the delay the queue adds after it.
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
        unset($payload[self::KEY], $payload['delay']);

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
