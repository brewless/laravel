<?php

declare(strict_types=1);

namespace Brewless\Laravel\Databases;

/**
 * A database Brewless made for one of the application's tenants, as Brewless
 * answers for it: its name, where it stands, and where it is reached once
 * Scaleway names that. It signs in with the application's own `DB_USERNAME`
 * and `DB_PASSWORD`; only the host differs.
 */
final readonly class DatabaseInfo
{
    public const string CREATING = 'creating';

    public const string READY = 'ready';

    public const string DELETING = 'deleting';

    public const string ERROR = 'error';

    public function __construct(
        public string $name,
        /** `creating`, `ready`, `deleting` or `error`. */
        public string $status,
        public ?string $host = null,
        public ?int $port = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $answer  the JSON Brewless answered with
     */
    public static function fromAnswer(array $answer): self
    {
        return new self(
            is_string($answer['name'] ?? null) ? $answer['name'] : '',
            is_string($answer['status'] ?? null) ? $answer['status'] : self::ERROR,
            is_string($answer['host'] ?? null) && $answer['host'] !== '' ? $answer['host'] : null,
            is_int($answer['port'] ?? null) ? $answer['port'] : null,
        );
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && $this->host !== null;
    }
}
