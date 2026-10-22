<?php

declare(strict_types=1);

namespace Brewless\Laravel\Databases;

/**
 * A database that did not become ready: Scaleway reports an error, it is
 * being removed or gone, or it took longer than the application waited.
 */
final class DatabaseNotReady extends DatabaseException
{
    public static function failed(string $name, string $status): self
    {
        return new self('The database '.$name.' did not become ready: it is '.$status.'.');
    }

    public static function timedOut(string $name, int $seconds): self
    {
        return new self('The database '.$name.' was not ready after '.$seconds.' seconds. It may still become ready: ask again later.');
    }

    public static function gone(string $name): self
    {
        return new self('The database '.$name.' is not known to Brewless.');
    }
}
