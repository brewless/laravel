<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Assert;

/**
 * The capacity client for an application's own tests, from
 * CapacityClient::fake(): nothing is sent, every boost is kept in memory, and
 * the assertions below say what the application asked for. A boost is not
 * ready until the test says so with markReady(); unreachable() makes every
 * call throw, to test that the application goes on without Brewless.
 */
final class FakeCapacity implements CapacityApi
{
    /** @var array<string, Boost> */
    private array $boosts = [];

    /** @var list<string> */
    private array $scheduled = [];

    /** @var list<string> */
    private array $cancelled = [];

    /** @var array<string, bool> */
    private array $readiness = [];

    private bool $unreachable = false;

    /**
     * @param  list<DatabaseCapacity>  $databases  what databases() answers
     */
    public function __construct(private array $databases = []) {}

    /** Whether boost `$key` is ready; every boost the test did not mark is not. */
    public function markReady(string $key, bool $ready = true): self
    {
        $this->readiness[$key] = $ready;

        return $this;
    }

    /** Every call from now on throws CapacityUnreachable, as when Brewless is down. */
    public function unreachable(bool $unreachable = true): self
    {
        $this->unreachable = $unreachable;

        return $this;
    }

    public function configured(): bool
    {
        return true;
    }

    public function databases(): array
    {
        $this->reach();

        return $this->databases;
    }

    public function schedule(string $key, DateTimeInterface $starts, DateTimeInterface $ends, int $min, int $max, int $prewarm = 15, int $cooldown = 30, ?string $name = null, ?string $database = null): Boost
    {
        $this->reach();

        return $this->keep(self::made($key, $name, $database, Boost::SCHEDULED, $min, $max, CarbonImmutable::instance($starts), CarbonImmutable::instance($ends), $prewarm, $cooldown));
    }

    public function boost(string $key, int $minutes, int $min, int $max, ?string $name = null, ?string $database = null): Boost
    {
        $this->reach();
        $now = CarbonImmutable::instance(Carbon::now());

        return $this->keep(self::made($key, $name, $database, Boost::ACTIVE, $min, $max, $now, $now->addMinutes($minutes), 0, 30));
    }

    public function reschedule(string $key, DateTimeInterface $starts, DateTimeInterface $ends): Boost
    {
        return $this->update($key, ['starts_at' => $starts, 'ends_at' => $ends]);
    }

    public function update(string $key, array $changes): Boost
    {
        $boost = $this->known($key);
        $time = static fn (string $field, CarbonImmutable $current): CarbonImmutable => ($changes[$field] ?? null) instanceof DateTimeInterface ? CarbonImmutable::instance($changes[$field]) : $current;
        $number = static fn (string $field, int $current): int => is_int($changes[$field] ?? null) ? $changes[$field] : $current;

        $startsAt = $time('starts_at', $boost->startsAt);
        $endsAt = is_int($changes['minutes'] ?? null) ? $startsAt->addMinutes($changes['minutes']) : $time('ends_at', $boost->endsAt);
        $prewarm = $number('prewarm_minutes', $boost->prewarmMinutes);
        $cooldown = $number('cooldown_minutes', $boost->cooldownMinutes);

        return $this->boosts[$key] = $boost->with(
            name: is_string($changes['name'] ?? null) ? $changes['name'] : $boost->name,
            minVcpu: $number('min_vcpu', $boost->minVcpu),
            maxVcpu: $number('max_vcpu', $boost->maxVcpu),
            startsAt: $startsAt,
            endsAt: $endsAt,
            prewarmMinutes: $prewarm,
            cooldownMinutes: $cooldown,
            windowStartsAt: $startsAt->subMinutes($prewarm),
            windowEndsAt: $endsAt->addMinutes($cooldown),
        );
    }

    public function status(string $key): ?Boost
    {
        $this->reach();
        $boost = $this->boosts[$key] ?? null;

        if ($boost === null || ! ($this->readiness[$key] ?? false)) {
            return $boost;
        }

        return $boost->with(ready: true, readyAt: $boost->readyAt ?? CarbonImmutable::instance(Carbon::now()), cpuCurrent: (float) $boost->minVcpu);
    }

    public function ready(string $key): bool
    {
        return $this->status($key)?->ready === true;
    }

    public function extend(string $key, DateTimeInterface $until): Boost
    {
        return $this->update($key, ['ends_at' => $until]);
    }

    public function cancel(string $key): Boost
    {
        $boost = $this->known($key);
        $this->cancelled[] = $key;

        return $this->boosts[$key] = $boost->with(status: Boost::CANCELLED, cancelledAt: CarbonImmutable::instance(Carbon::now()));
    }

    /**
     * Assert that a boost of this key was scheduled or started and, with a callback, that it passes the
     * callback as it stands now.
     *
     * @param  (Closure(Boost): bool)|null  $callback
     */
    public function assertScheduled(string $key, ?Closure $callback = null): void
    {
        Assert::assertContains($key, $this->scheduled, 'No boost "'.$key.'" was scheduled.');

        if ($callback !== null) {
            Assert::assertTrue($callback($this->boosts[$key]), 'Boost "'.$key.'" was scheduled, but not as expected.');
        }
    }

    public function assertNotScheduled(string $key): void
    {
        Assert::assertNotContains($key, $this->scheduled, 'Boost "'.$key.'" was scheduled.');
    }

    public function assertNothingScheduled(): void
    {
        Assert::assertSame([], $this->scheduled, 'Boosts were scheduled: '.implode(', ', array_unique($this->scheduled)).'.');
    }

    public function assertCancelled(string $key): void
    {
        Assert::assertContains($key, $this->cancelled, 'Boost "'.$key.'" was not cancelled.');
    }

    public function assertNotCancelled(string $key): void
    {
        Assert::assertNotContains($key, $this->cancelled, 'Boost "'.$key.'" was cancelled.');
    }

    /**
     * Every boost asked for, by key, as it stands now.
     *
     * @return array<string, Boost>
     */
    public function boosts(): array
    {
        return $this->boosts;
    }

    private static function made(string $key, ?string $name, ?string $database, string $status, int $min, int $max, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $prewarm, int $cooldown): Boost
    {
        return new Boost(
            key: $key,
            name: $name ?? $key,
            database: $database ?? 'main',
            environment: 'testing',
            source: 'api',
            status: $status,
            ready: false,
            readyAt: null,
            minVcpu: $min,
            maxVcpu: $max,
            startsAt: $startsAt,
            endsAt: $endsAt,
            prewarmMinutes: $prewarm,
            cooldownMinutes: $cooldown,
            windowStartsAt: $startsAt->subMinutes($prewarm),
            windowEndsAt: $endsAt->addMinutes($cooldown),
            cpuCurrent: null,
            failureReason: null,
            cancelledAt: null,
            estimateFloorCents: 0,
            estimateCeilingCents: 0,
            estimateMinutes: 0,
            currency: 'EUR',
            createdAt: CarbonImmutable::instance(Carbon::now()),
        );
    }

    /**
     * Like Brewless: the same key a second time gives the boost it made the first time.
     */
    private function keep(Boost $boost): Boost
    {
        $this->boosts[$boost->key] ??= $boost;
        $this->scheduled[] = $boost->key;

        return $this->boosts[$boost->key];
    }

    /**
     * @throws CapacityException
     */
    private function known(string $key): Boost
    {
        $this->reach();

        return $this->boosts[$key] ?? throw CapacityRefused::answered(404, 'There is no boost '.$key.' for this environment.');
    }

    /**
     * @throws CapacityUnreachable
     */
    private function reach(): void
    {
        if ($this->unreachable) {
            throw CapacityUnreachable::because('the fake is unreachable', 1);
        }
    }
}
