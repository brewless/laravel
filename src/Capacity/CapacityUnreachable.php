<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

use Throwable;

/**
 * Brewless or Scaleway could not be reached, or asked to slow down, on every
 * try. Asking again later with the same boost key is safe: Brewless makes a
 * boost once per key.
 */
final class CapacityUnreachable extends CapacityException
{
    public static function because(string $reason, int $tries, ?Throwable $previous = null): self
    {
        return new self('Brewless could not be reached for a capacity boost: '.$reason.', after '.$tries.' '.($tries === 1 ? 'try' : 'tries').'. Ask again in a moment.', 0, $previous);
    }
}
