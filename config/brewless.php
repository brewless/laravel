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

        // Wrong secrets an address may give per minute. After that it gets the same 404 for a minute, right or wrong.
        'max_failures' => 10,

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

        // Console commands that throw data away. That is a step someone takes on purpose, at the console, never a side effect of a request.
        'destructive' => ['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'],
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
    |
    | The same goes for the visitor's address. Every proxy appends what it saw
    | to X-Forwarded-For and a visitor can put anything in front, so with an
    | edge secret set only the entry the edge appended counts, and a request
    | without the secret loses the header altogether.
    */
    'edge' => [
        'secret' => env('BREWLESS_EDGE_SECRET'),
        'secret_header' => 'X-Brewless-Edge',
        'host_header' => 'Cdn-Host',

        // How many proxies append to X-Forwarded-For between the visitor and
        // the container: the edge itself, plus whatever stands in front of the
        // container. The visitor's address is that many from the end.
        'hops' => (int) env('BREWLESS_EDGE_HOPS', 1),

        // Domains that are yours. A hostname is believed when it is one of these or a subdomain of one.
        'domains' => array_values(array_filter(explode(',', (string) env('BREWLESS_DOMAINS', (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST))))),
    ],

    /*
    | A queue at Scaleway Queues.
    |
    | Scaleway's queues speak SQS but take a key of their own, not the key
    | your files in Object Storage are read with. Laravel's `sqs` connection
    | reads the same AWS_* names as its `s3` disk, so the queue's key travels
    | under names of its own and is handed to that connection here. Without
    | them the connection is left as your application configured it.
    */
    'queue' => [
        'key' => env('SQS_ACCESS_KEY_ID'),
        'secret' => env('SQS_SECRET_ACCESS_KEY'),
        'endpoint' => env('SQS_ENDPOINT'),
    ],
];
