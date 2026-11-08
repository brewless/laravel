<?php

declare(strict_types=1);

return [
    // The image this container runs; Brewless sets it on every release.
    'release' => env('RELEASE'),

    /*
    | The time zone of the schedule.
    |
    | Brewless is made for Europe: `->dailyAt('07:00')` means 07:00 in
    | Amsterdam, summer and winter, unless the application sets
    | `app.schedule_timezone` itself or SCHEDULE_TIMEZONE says otherwise
    | (`SCHEDULE_TIMEZONE=UTC` keeps the schedule in UTC). Only the schedule:
    | the time zone the application stores and shows times in stays its own.
    */
    'schedule_timezone' => env('SCHEDULE_TIMEZONE', 'Europe/Amsterdam'),

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

        // How long PHP lets an ops request run, in seconds, instead of the 30 of
        // php.ini-production: release commands, a queue run and a command
        // take longer. The container's own timeout at the provider still applies.
        'time_limit' => 900,

        // Wrong secrets an address may give per minute before it is logged. It is
        // never shut out: the right secret, or a signed job, always passes.
        'max_failures' => 10,

        // What runs before a release goes live, in this order. If one fails, the release does not go live.
        'release_commands' => ['migrate --force'],

        // Where the scheduler and the queue leave their sign of life. Not the
        // database: that would be a write every minute to say nothing happened.
        'heartbeat_store' => env('BREWLESS_HEARTBEAT_STORE', 'file'),

        // How long a job signed for a queue trigger is good for, in seconds,
        // counted from when it was queued (or queued again). A message that
        // arrives later is dropped, and within this time each delivery runs
        // once. Longer than the longest delay a queue at the provider takes
        // (15 minutes) plus the time a message may wait for the container.
        'job_max_age' => (int) env('BREWLESS_JOB_MAX_AGE', 3600),

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

        // The secret before this one, which counts just as much. Brewless
        // sets it while it replaces the secret and takes it away again right
        // after: the edge and the container cannot change in the same
        // instant, and for the minutes in between the container answers to
        // both. Nothing to set by hand, and nothing to leave standing.
        'previous_secret' => env('BREWLESS_EDGE_SECRET_PREVIOUS'),

        'secret_header' => 'X-Brewless-Edge',
        'host_header' => 'Cdn-Host',

        // How many entries the edge writes into X-Forwarded-For: bunny.net's
        // edge and its Origin Shield, which every pull zone Brewless manages
        // has. Addresses of the container's own network that the provider
        // appends behind them are skipped by themselves. The visitor's address
        // is that many from the end of what is left.
        'hops' => (int) env('BREWLESS_EDGE_HOPS', 2),

        // Domains that are yours. A hostname is believed when it is one of these or a subdomain of one.
        'domains' => array_values(array_filter(explode(',', (string) env('BREWLESS_DOMAINS', (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST))))),

        // How many seconds the edge may keep an answer that is the same for
        // everybody (Http\Middleware\CacheAtEdge): a GET without cookies or
        // Authorization, answered without a cookie, a session or a
        // Cache-Control of the application's own. Every release empties the
        // edge. 0 caches nothing.
        'cache' => (int) env('BREWLESS_EDGE_CACHE', 3600),

        // A guest without any cookie gets no session cookie and nothing is stored when an HTML page put
        // nothing in the session and shows no CSRF token (Http\Middleware\StartSessionWhenNeeded), so the
        // edge may keep that page. Unset: on where Laravel's CSRF check accepts a same-origin request without
        // a token (Laravel 13). true turns it on on Laravel 12 too; false keeps a session on every page.
        'stateless_guests' => env('BREWLESS_STATELESS_GUESTS'),
    ],

    /*
    | The test address Brewless gives every environment.
    |
    | Brewless points `<organisation>-<application>-<environment>` under its
    | own test domain straight at the web container. An application that only
    | answers on its own domain would not know that name, so a request for it
    | is handled as a request for the host of APP_URL, and every answer on it
    | tells search engines to stay away: `X-Robots-Tag: noindex, nofollow`,
    | and a robots.txt that disallows everything.
    */
    'preview' => [
        'domains' => array_values(array_filter(explode(',', (string) env('BREWLESS_PREVIEW_DOMAINS', 'brewless.dev')))),
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

    /*
    | A cache at Scaleway (`cache` in brewless.yml).
    |
    | A Managed Database for Redis is reached over the Private Network, over
    | TLS, with a certificate of its own cluster that no public authority
    | signed. Brewless gives the application that certificate (base64) here;
    | the redis connections then speak TLS and trust that certificate and no
    | other. Without it they are left as your application configured them.
    */
    'redis' => [
        'ca' => env('BREWLESS_REDIS_CA'),
        'connections' => ['default', 'cache'],
        // The nodes of a cache in cluster mode, comma-separated host:port; set by Brewless with `cache.nodes` in brewless.yml.
        'nodes' => env('BREWLESS_REDIS_NODES'),
    ],

    /*
    | A database per tenant (`databases` in brewless.yml).
    |
    | On a Serverless SQL Database at Scaleway a database cannot be made over
    | SQL: each is a resource of its own, made through Scaleway's API. The
    | application asks Brewless for one instead, with the client
    | Brewless\Laravel\Databases\DatabaseClient. Brewless gives every
    | container these three; every request is signed with the key, which is
    | never sent itself.
    */
    'databases' => [
        'url' => env('BREWLESS_API_URL'),
        'environment' => env('BREWLESS_ENVIRONMENT'),
        'key' => env('BREWLESS_DATABASES_KEY'),

        // Seconds a request to Brewless may take, and may take to connect.
        'timeout' => 15,
        'connect_timeout' => 5,
    ],

    /*
    | Capacity boosts for the environment's Serverless SQL Database.
    |
    | More vCPU for a while, for a peak the application knows is coming: a
    | ticket sale, a newsletter. The application asks Brewless with the client
    | Brewless\Laravel\Capacity\CapacityClient. Brewless gives every
    | container these three once the owner switched the capacity key on in
    | the console; every request is signed with the key, which is never sent
    | itself.
    */
    'capacity' => [
        'url' => env('BREWLESS_API_URL'),
        'environment' => env('BREWLESS_ENVIRONMENT'),
        'key' => env('BREWLESS_CAPACITY_KEY'),

        // Seconds a request to Brewless may take, and may take to connect.
        'timeout' => 10,
        'connect_timeout' => 5,

        // Milliseconds to wait before each new try when Brewless or Scaleway
        // could not be reached, was busy (5xx) or asked to slow down (429).
        'retry_milliseconds' => [500, 1000, 2000],
    ],

    /*
    | The application's own database. A query whose connection went away, as
    | when the provider restarts the database for maintenance, is tried again
    | for up to this many seconds instead of once (never inside a
    | transaction). 0 keeps Laravel's own single try.
    |
    | `persistent` keeps the PostgreSQL and MySQL connections open between
    | requests: each PHP thread of FrankenPHP (two per vCPU) holds one, so a
    | request skips the TLS handshake with the database. Measured on Serverless
    | SQL (BL-248): 184 to 335 requests a second on the same containers, the
    | median from 6.3 s to 67 ms. Every container then holds as many
    | connections as it has threads, also while it is quiet; an open
    | connection does not keep a Serverless SQL Database awake.
    */
    'database' => [
        'retry_seconds' => (int) env('BREWLESS_DB_RETRY_SECONDS', 60),
        'persistent' => filter_var(env('BREWLESS_DB_PERSISTENT', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    | A copy of every mail the application sends (`mail.keep_days` in
    | brewless.yml), for the Brewless console to show its headers and a
    | preview: the provider keeps neither. The copy goes to a private bucket
    | of the environment's own, which Object Storage empties after those
    | days, under the id Scaleway Transactional Email queued the mail as.
    | Brewless gives the container a key that may only put there. Storing a
    | copy never stops a mail: when it fails, it is only logged.
    */
    'mail' => [
        'copies' => [
            'bucket' => env('BREWLESS_MAIL_COPIES_BUCKET'),
            'endpoint' => env('BREWLESS_MAIL_COPIES_ENDPOINT'),
            'region' => env('BREWLESS_MAIL_COPIES_REGION'),
            'key' => env('BREWLESS_MAIL_COPIES_ACCESS_KEY_ID'),
            'secret' => env('BREWLESS_MAIL_COPIES_SECRET_ACCESS_KEY'),
            'timeout' => 10,
        ],

        /*
        | What became of each mail sent through Transactional Email: Brewless
        | subscribes the topic it reports to (Topics and Events, Scaleway's
        | SNS) to POST /_ops/mail-events. A notification is believed only
        | when Scaleway signed it, with a certificate from one of these hosts,
        | and only from this topic; each becomes a Brewless\Laravel\Mail\MailEvent.
        */
        'events' => [
            'topic' => env('BREWLESS_MAIL_EVENTS_TOPIC'),
            'certificate_hosts' => ['messaging.s3.fr-par.scw.cloud'],
            'subscribe_hosts' => ['sns.mnq.fr-par.scaleway.com'],
        ],
    ],
];
