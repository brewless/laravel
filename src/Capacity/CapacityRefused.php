<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

/**
 * Brewless answered and said no. `status` is the HTTP status: 401 a wrong
 * signature or a clock more than five minutes off, 403 a key that is switched
 * off or a plan without capacity boosts, 404 a boost or database it does not
 * know, 409 a boost key used before for another boost, 422 a value it does
 * not take or a limit of the key (`errors` names the fields). Asking again
 * the same way gives the same answer.
 */
final class CapacityRefused extends CapacityException
{
    /**
     * @param  array<string, list<string>>  $errors  per field, what Brewless did not take (422 only)
     */
    public function __construct(string $message, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }

    /**
     * @param  array<array-key, mixed>  $errors
     */
    public static function answered(int $status, ?string $message, array $errors = []): self
    {
        $fields = [];

        foreach ($errors as $field => $messages) {
            if (is_string($field) && is_array($messages)) {
                $fields[$field] = array_values(array_filter($messages, is_string(...)));
            }
        }

        return new self('Brewless refused the request ('.$status.')'.($message !== null && $message !== '' ? ': '.$message : '.'), $status, $fields);
    }

    /** The boost key was used before, for a boost with other values. */
    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    /** A value Brewless does not take, or a limit of the key: see `errors`. */
    public function isInvalid(): bool
    {
        return $this->status === 422;
    }
}
