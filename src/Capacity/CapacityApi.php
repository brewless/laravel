<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

use DateTimeInterface;

/**
 * Capacity boosts for the environment's Serverless SQL Database: more vCPU
 * for a while, for a peak the application knows is coming. Type-hint this
 * interface (or resolve it), so a test can swap in CapacityClient::fake().
 *
 * Every method throws a CapacityException when it cannot do its work: catch
 * it, and let the application go on without the boost.
 */
interface CapacityApi
{
    /** Whether the environment has a capacity key: the three variables are there. */
    public function configured(): bool;

    /**
     * The databases the environment may boost, with their bounds now.
     *
     * @return list<DatabaseCapacity>
     *
     * @throws CapacityException
     */
    public function databases(): array;

    /**
     * Plan a boost from `$starts` to `$ends`: the database is raised `$prewarm` minutes before the start and
     * lowered `$cooldown` minutes after the end. Sending the same key with the same values again gives the
     * same boost; the same key with other values is refused (409): use reschedule() or update() for that.
     *
     * @throws CapacityException
     */
    public function schedule(string $key, DateTimeInterface $starts, DateTimeInterface $ends, int $min, int $max, int $prewarm = 15, int $cooldown = 30, ?string $name = null, ?string $database = null): Boost;

    /**
     * Start a boost now, for `$minutes`.
     *
     * @throws CapacityException
     */
    public function boost(string $key, int $minutes, int $min, int $max, ?string $name = null, ?string $database = null): Boost;

    /**
     * Move a boost that has not begun to another start and end.
     *
     * @throws CapacityException
     */
    public function reschedule(string $key, DateTimeInterface $starts, DateTimeInterface $ends): Boost;

    /**
     * Change a boost that has not begun: any of `name`, `min_vcpu`, `max_vcpu`, `starts_at`, `ends_at`,
     * `minutes`, `prewarm_minutes` and `cooldown_minutes`. Once it began, only a later `ends_at`.
     *
     * @param  array<string, string|int|DateTimeInterface>  $changes
     *
     * @throws CapacityException
     */
    public function update(string $key, array $changes): Boost;

    /**
     * Where the boost stands; null when Brewless has no boost of this key for the environment.
     *
     * @throws CapacityException
     */
    public function status(string $key): ?Boost;

    /**
     * Whether Scaleway reports the database at the boost's minimum. False for a boost Brewless does not know.
     *
     * @throws CapacityException
     */
    public function ready(string $key): bool;

    /**
     * Let the boost run until `$until`, later than its end now.
     *
     * @throws CapacityException
     */
    public function extend(string $key, DateTimeInterface $until): Boost;

    /**
     * Call the boost off. One that has not begun is cancelled at once; a running one ends now and cools down.
     *
     * @throws CapacityException
     */
    public function cancel(string $key): Boost;
}
