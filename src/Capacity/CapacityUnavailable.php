<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

/**
 * The environment has no capacity key: one of BREWLESS_API_URL,
 * BREWLESS_ENVIRONMENT and BREWLESS_CAPACITY_KEY is missing. Nothing was
 * sent. Expected on a laptop, in a test and in an environment where the owner
 * did not switch the capacity key on.
 */
final class CapacityUnavailable extends CapacityException
{
    public static function notConfigured(): self
    {
        return new self('Brewless capacity boosts are not configured: BREWLESS_API_URL, BREWLESS_ENVIRONMENT and BREWLESS_CAPACITY_KEY are all needed. Switch the capacity key on for the environment in the Brewless console and deploy.');
    }
}
