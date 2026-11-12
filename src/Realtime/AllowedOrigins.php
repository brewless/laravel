<?php

declare(strict_types=1);

namespace Brewless\Laravel\Realtime;

/**
 * Who may connect to the realtime server (Laravel Reverb) of the application.
 *
 * Reverb's own configuration lets a page on any website connect
 * (`'allowed_origins' => ['*']`) and reads no variable for it. Brewless names
 * the hostnames of the environment in REVERB_ALLOWED_ORIGINS: the host of
 * APP_URL and every domain in brewless.yml. This puts them where Reverb
 * reads them, for every Reverb app that still allows everyone. A list the
 * application wrote in config/reverb.php itself is left alone.
 */
final class AllowedOrigins
{
    public static function limit(): void
    {
        $hosts = self::hosts(config('brewless.realtime.allowed_origins'));
        $apps = config('reverb.apps.apps');

        // Nothing named, or every origin: as Reverb has it.
        if ($hosts === [] || in_array('*', $hosts, true) || ! is_array($apps)) {
            return;
        }

        foreach ($apps as $index => $app) {
            if (is_array($app) && ($app['allowed_origins'] ?? ['*']) === ['*']) {
                config(['reverb.apps.apps.'.$index.'.allowed_origins' => $hosts]);
            }
        }
    }

    /**
     * Reverb compares each name (a * matches anything) with the host of the
     * Origin header, without scheme or port: a URL or a port in the list is
     * reduced to its host.
     *
     * @return list<string>
     */
    private static function hosts(mixed $origins): array
    {
        if (! is_string($origins)) {
            return [];
        }

        $hosts = [];

        foreach (explode(',', $origins) as $origin) {
            $origin = trim($origin);
            $host = $origin === '*' ? '*' : parse_url(str_contains($origin, '://') ? $origin : '//'.$origin, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }
}
