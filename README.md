# brewless/laravel

What a Laravel application needs to run on [Brewless](https://brewless.eu).
It runs in **your** containers, in your own cloud account. It does not call
Brewless, sends nothing anywhere and keeps working when you stop using
Brewless.

```bash
composer require brewless/laravel
```

There is nothing to register. Laravel 12 and 13, PHP 8.3 and up.

## What it adds

### Release operations over HTTP

Nothing stays running on a serverless container, and a one-off job cannot
reach a private database. So the containers of an environment answer on a few
routes of their own:

| Route | What it does |
| --- | --- |
| `POST /_ops/migrate` | Runs the release commands (`migrate --force`) before a release goes live; with `{"commands": [...]}` those instead |
| `POST /` with `{"task": "tick"}` | One scheduler tick, then the queue; the body a cron trigger sends. `{"task": "queue"}` only works the queue, and `"queues": ["mail", "default"]` names the queues to work, the first first |
| `POST /_ops/schedule`, `POST /_ops/queue` | The same two, separately |
| `POST /` with a queued job | A job a queue trigger delivers (Scaleway Queues), run once its signature holds; see below |
| `GET /_ops/status` | Last tick, jobs waiting, jobs failed (which, never why), and the moments of the schedule |
| `POST /_ops/queue/retry` | Puts every failed job back on the queue |
| `POST /_ops/command` | Runs one console command, without questions |

Every route needs the secret from `BREWLESS_OPS_SECRET`, in the header
`X-Brewless-Ops` or as `secret` in the JSON body. **A container without that
variable has none of these routes**: they answer 404, the same as a path
that does not exist. Give the secret only to the containers that should do
this work; the container that serves visitors does not need it.

PHP ends a web request after 30 seconds by default, shorter than release
commands or a command may take. A request with the secret gets
`ops.time_limit` seconds instead (900); the container's own timeout at your
provider still applies.

### Queue triggers

A queue at Scaleway Queues can call the worker as soon as a job is put on
it, so nothing has to look at the queue every minute. Such a trigger posts
the job itself to `/`, without any secret. So every job the `sqs` and
`database` connections queue is signed, and only a job whose signature holds
is run: a job is code that gets unserialised. The signing key is derived from
the application key (`APP_KEY`), not from `BREWLESS_OPS_SECRET`: every
container of the application queues jobs, also the one that serves visitors
and has no ops secret, and all of them share the application key. After a
rotation of `APP_KEY`, a job signed with a key in `APP_PREVIOUS_KEYS` still
runs.
It runs the way `queue:work` runs it, with its tries, backoff, `failed()`
and the failed jobs table. The answer is always a success, also for a job
that failed or that was dropped: the provider would otherwise deliver it
again every half minute, without end.

Commands that never return (`queue:work`, `tinker`, `serve`, …) and commands
that throw data away (`migrate:fresh`, `db:wipe`, …) are refused on
`/_ops/command`, and as release commands sent along with a release.
Brewless sends the release commands and queues of your project's
`brewless.yml`; without them the commands of `ops.release_commands` and the
default queue are used. An address that gives a wrong secret ten times in a minute
gets the same 404 for a minute, whatever it sends. A tick that finds no job
due does not start a worker, so an idle application costs a single query.

### The visitor's hostname behind the edge

Scaleway routes a request on its Host header and only knows the container's
own domain, so the edge sends that Host and carries the real hostname in
`Cdn-Host`. The package puts it back, but only when the request also carries
the secret from `BREWLESS_EDGE_SECRET`, and only for the domains in
`BREWLESS_DOMAINS` (default: the host of `APP_URL`) and their subdomains.
Calling the container directly with a made-up hostname changes nothing.

The visitor's address is treated the same way. With an edge secret set, only
the entry the edge appended to `X-Forwarded-For` is kept, and a request
without the secret loses the header, so rate limits count the real visitor
and nobody chooses their own address. `BREWLESS_EDGE_HOPS` says how many
proxies append to the header (default 1). Without an edge secret the header
is left as it arrived.

### The test address

Brewless gives every environment a test address, such as
`acme-shop-production.brewless.dev`, that leads straight to the web
container. A request on it is handled as a request for the host of `APP_URL`,
so an application that ties its routes to its own domain answers there too.
Every answer of the application on the test address carries
`X-Robots-Tag: noindex, nofollow`, and its `robots.txt` disallows everything.
A `public/robots.txt` of your own is the exception: the web server hands out
existing files before the application runs, so that file is served as it is.
The pages still carry `noindex`, which is what keeps them out of search results. Your own domains are left alone. The request carries the
attribute `brewless.preview` when it came in on the test address.

## Settings

| Variable | Meaning |
| --- | --- |
| `BREWLESS_OPS_SECRET` | Turns the ops routes on, on this container |
| `BREWLESS_EDGE_SECRET` | The secret the edge adds to every request |
| `BREWLESS_DOMAINS` | Comma-separated domains that are yours |
| `BREWLESS_EDGE_HOPS` | Proxies between the visitor and the container (`1`) |
| `BREWLESS_PREVIEW_DOMAINS` | Comma-separated test domains (`brewless.dev`); empty turns the test address off |
| `BREWLESS_HEARTBEAT_STORE` | Cache store for the sign of life (`file`) |
| `SQS_ACCESS_KEY_ID`, `SQS_SECRET_ACCESS_KEY`, `SQS_ENDPOINT` | The key and address of a queue at Scaleway Queues; handed to the `sqs` connection, which otherwise shares `AWS_*` with the `s3` disk |
| `RELEASE` | The running image; set by Brewless on each release |

Other release commands, queue time and tries, or more commands to refuse:

```bash
php artisan vendor:publish --tag=brewless-config
```

Your `config/brewless.php` only has to name what it changes; every setting it
leaves out keeps the package's value.

## Without Brewless

The routes are plain HTTP. A cron trigger of your provider that posts
`{"secret": "…", "task": "tick"}` to the worker container every minute is all
the scheduler and the queue need; `curl -X POST -H "X-Brewless-Ops: …"
https://<container>/_ops/migrate` runs your migrations.

## Development

```bash
composer install
vendor/bin/pest
vendor/bin/pint
```

## Licence

MIT. See [LICENSE](LICENSE).
