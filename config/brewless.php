<?php

declare(strict_types=1);

return [
    // The image this container runs; Brewless sets it on every release.
    'release' => env('RELEASE'),

    /*
    | Release operations over HTTP.
    |
    | Nothing stays running on a serverless container, and a one-off job
    | cannot reach a private database. So the containers of an environment
    | answer on a few routes of their own: to run the release commands before
    | a new version goes live, to tick the scheduler and work the queue, and to
    | run a single command. The routes only exist on a container that was
    | given the secret; without it every one of them is a plain 404.
    */
    'ops' => [
        'secret' => env('BREWLESS_OPS_SECRET'),
        'secret_header' => 'X-Brewless-Ops',

        // What runs before a release goes live, in this order. If one fails, the release does not go live.
        'release_commands' => ['migrate --force'],

        // Where the scheduler and the queue leave their sign of life. Not the
        // database: that would be a write every minute to say nothing happened.
        'heartbeat_store' => env('BREWLESS_HEARTBEAT_STORE', 'file'),

        // One call works the queue for at most this long: inside one trigger interval and one request.
        'queue_seconds' => 45,
        'queue_tries' => 3,

        // Console commands that never return. Asked for through Brewless, they are refused.
        'never_ending' => ['tinker', 'serve', 'queue:work', 'queue:listen', 'schedule:work', 'pail', 'reverb:start', 'horizon', 'octane:start', 'dev'],
    ],

    /*
    | The edge in front of the application (bunny.net).
    |
    | Scaleway routes a request on its Host header and only knows the
    | container's own domain. The edge therefore sends that Host and carries
    | the visitor's hostname in a header of its own. The application believes
    | that header only together with the secret the edge adds, and only for
    | the domains listed here, so nobody can choose a hostname by calling the
    | container directly.
    */
    'edge' => [
        'secret' => env('BREWLESS_EDGE_SECRET'),
        'secret_header' => 'X-Brewless-Edge',
        'host_header' => 'Cdn-Host',

        // Domains that are yours. A hostname is believed when it is one of these or a subdomain of one.
        'domains' => array_values(array_filter(explode(',', (string) env('BREWLESS_DOMAINS', (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST))))),
    ],
];
