<?php

declare(strict_types=1);

namespace Brewless\Laravel\Databases;

/**
 * Brewless answered and said no: a wrong signature or a clock more than five
 * minutes off (401), a name it does not make (422), a blocked organisation
 * (423). Asking again the same way gives the same answer.
 */
final class RequestRefused extends DatabaseException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }

    public static function answered(int $status, ?string $message): self
    {
        return new self('Brewless refused the request ('.$status.')'.($message !== null && $message !== '' ? ': '.$message : '.'), $status);
    }
}
