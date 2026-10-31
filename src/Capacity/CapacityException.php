<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

use RuntimeException;

/**
 * Something the capacity client could not do. Catch this one around every
 * call: a boost helps the database through a peak, and the application's own
 * work should never wait on Brewless for it. The message never holds the key.
 */
class CapacityException extends RuntimeException
{
    public static function invalidKey(string $key): self
    {
        return new self('"'.$key.'" is not a boost key Brewless takes: use 1 to 100 letters, digits, dots, colons, underscores and dashes.');
    }

    public static function unexpectedAnswer(int $status): self
    {
        return new self('Brewless answered '.$status.' without the boost or databases the client expected.');
    }

    public static function unreadable(string $reason): self
    {
        return new self('The request for Brewless could not be written as JSON: '.$reason.'.');
    }
}
