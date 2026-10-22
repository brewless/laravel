<?php

declare(strict_types=1);

namespace Brewless\Laravel\Databases;

use Throwable;

/**
 * Brewless or Scaleway could not be reached, or asked to slow down. Nothing
 * is lost: asking again later is safe, also for a database that is being made.
 */
final class BrewlessUnreachable extends DatabaseException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('Brewless could not be reached for a database: '.$reason.'. Ask again in a moment.', 0, $previous);
    }
}
