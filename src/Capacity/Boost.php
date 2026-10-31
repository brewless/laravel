<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A capacity boost as Brewless answers for it: the database gets at least
 * `minVcpu` and at most `maxVcpu` from `startsAt` to `endsAt`, raised
 * `prewarmMinutes` before the start and lowered `cooldownMinutes` after the
 * end (the window). `ready` is true only once Scaleway reports the database
 * at `minVcpu`. The estimate is in cents, excluding VAT.
 */
final readonly class Boost
{
    public const string SCHEDULED = 'scheduled';

    public const string PREWARMING = 'prewarming';

    public const string READY = 'ready';

    public const string ACTIVE = 'active';

    public const string COOLING_DOWN = 'cooling_down';

    public const string ENDED = 'ended';

    public const string CANCELLED = 'cancelled';

    public const string NOT_REACHED = 'not_reached';

    public const string FAILED = 'failed';

    public function __construct(
        public string $key,
        public string $name,
        public string $database,
        public string $environment,
        /** `console`, `api` or `cli`: where the boost was asked for. */
        public string $source,
        /** One of the constants of this class. */
        public string $status,
        public bool $ready,
        public ?CarbonImmutable $readyAt,
        public int $minVcpu,
        public int $maxVcpu,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $prewarmMinutes,
        public int $cooldownMinutes,
        public CarbonImmutable $windowStartsAt,
        public CarbonImmutable $windowEndsAt,
        /** What Scaleway gives the database now, while the boost is in its window; otherwise null. */
        public ?float $cpuCurrent,
        public ?string $failureReason,
        public ?CarbonImmutable $cancelledAt,
        public int $estimateFloorCents,
        public int $estimateCeilingCents,
        public int $estimateMinutes,
        public string $currency,
        public ?CarbonImmutable $createdAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $answer  the `data` Brewless answered with
     *
     * @throws CapacityException when the answer has no key or no times
     */
    public static function fromAnswer(array $answer, int $status = 200): self
    {
        $key = $answer['key'] ?? null;
        $startsAt = self::time($answer['starts_at'] ?? null);
        $endsAt = self::time($answer['ends_at'] ?? null);

        if (! is_string($key) || $key === '' || $startsAt === null || $endsAt === null) {
            throw CapacityException::unexpectedAnswer($status);
        }

        $estimate = is_array($answer['estimate'] ?? null) ? $answer['estimate'] : [];

        return new self(
            $key,
            self::text($answer['name'] ?? null) ?? $key,
            self::text($answer['database'] ?? null) ?? '',
            self::text($answer['environment'] ?? null) ?? '',
            self::text($answer['source'] ?? null) ?? '',
            self::text($answer['status'] ?? null) ?? '',
            ($answer['ready'] ?? false) === true,
            self::time($answer['ready_at'] ?? null),
            self::number($answer['min_vcpu'] ?? null),
            self::number($answer['max_vcpu'] ?? null),
            $startsAt,
            $endsAt,
            self::number($answer['prewarm_minutes'] ?? null),
            self::number($answer['cooldown_minutes'] ?? null),
            self::time($answer['window_starts_at'] ?? null) ?? $startsAt,
            self::time($answer['window_ends_at'] ?? null) ?? $endsAt,
            is_int($answer['cpu_current'] ?? null) || is_float($answer['cpu_current'] ?? null) ? (float) $answer['cpu_current'] : null,
            self::text($answer['failure_reason'] ?? null),
            self::time($answer['cancelled_at'] ?? null),
            self::number($estimate['floor_cents'] ?? null),
            self::number($estimate['ceiling_cents'] ?? null),
            self::number($estimate['minutes'] ?? null),
            self::text($estimate['currency'] ?? null) ?? 'EUR',
            self::time($answer['created_at'] ?? null),
        );
    }

    /**
     * Whether the boost is over and will not raise the database again: it ended, was cancelled, failed, or
     * its window passed before the database reached the minimum.
     */
    public function isOver(): bool
    {
        return in_array($this->status, [self::ENDED, self::CANCELLED, self::NOT_REACHED, self::FAILED], true);
    }

    /**
     * A copy with some properties changed, by name: `$boost->with(status: Boost::CANCELLED)`.
     */
    public function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function number(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
