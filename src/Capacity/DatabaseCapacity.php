<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

/**
 * A Serverless SQL Database the environment may boost, as Brewless answers
 * for it: its normal bounds in vCPU (`baseline`), the bounds wanted now with
 * every boost counted in (`effective`), and what Scaleway gives it right now.
 */
final readonly class DatabaseCapacity
{
    public function __construct(
        public string $name,
        public string $region,
        /** Whether other environments use the same database. */
        public bool $shared,
        public int $baselineMin,
        public int $baselineMax,
        public int $effectiveMin,
        public int $effectiveMax,
        /** The vCPU Scaleway reports for it now; null when Brewless could not ask. */
        public ?float $cpuCurrent,
        public string $status,
    ) {}

    /**
     * @param  array<array-key, mixed>  $answer  one entry of `data`
     */
    public static function fromAnswer(array $answer): self
    {
        $baseline = is_array($answer['baseline'] ?? null) ? $answer['baseline'] : [];
        $effective = is_array($answer['effective'] ?? null) ? $answer['effective'] : [];

        return new self(
            is_string($answer['name'] ?? null) ? $answer['name'] : '',
            is_string($answer['region'] ?? null) ? $answer['region'] : '',
            ($answer['shared'] ?? false) === true,
            is_int($baseline['min'] ?? null) ? $baseline['min'] : 0,
            is_int($baseline['max'] ?? null) ? $baseline['max'] : 0,
            is_int($effective['min'] ?? null) ? $effective['min'] : 0,
            is_int($effective['max'] ?? null) ? $effective['max'] : 0,
            is_int($answer['cpu_current'] ?? null) || is_float($answer['cpu_current'] ?? null) ? (float) $answer['cpu_current'] : null,
            is_string($answer['status'] ?? null) ? $answer['status'] : '',
        );
    }
}
