<?php

declare(strict_types=1);

namespace Brewless\Laravel\Databases;

use RuntimeException;

/**
 * Something the database client could not do. The message names what went
 * wrong and, where it helps, the database; never the key.
 */
class DatabaseException extends RuntimeException
{
    /** Brewless is not configured: one of the three variables is missing. */
    public static function notConfigured(): self
    {
        return new self('Brewless databases are not configured: BREWLESS_API_URL, BREWLESS_ENVIRONMENT and BREWLESS_DATABASES_KEY are all needed. Add `databases` to the environment in brewless.yml and deploy.');
    }

    public static function invalidName(string $name): self
    {
        return new self('"'.$name.'" is not a database name Brewless makes: use lowercase letters, digits and underscores, at most 63.');
    }
}
