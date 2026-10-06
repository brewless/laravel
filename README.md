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
| `POST /_ops/migrate` | Runs the release commands (`migrate --force`) before a release goes live |
| `POST /` with `{"task": "tick"}` | One scheduler tick, then the queue; the body a cron trigger sends |
| `POST /_ops/schedule`, `POST /_ops/queue` | The same two, separately |
| `GET /_ops/status` | Last tick, jobs waiting, jobs failed (which, never why) |
| `POST /_ops/queue/retry` | Puts every failed job back on the queue |
| `POST /_ops/command` | Runs one console command, without questions |

Every route needs the secret from `BREWLESS_OPS_SECRET`, in the header
`X-Brewless-Ops` or as `secret` in the JSON body. **A container without that
variable has none of these routes**: they answer 404, the same as a path
that does not exist. Give the secret only to the containers that should do
this work; the container that serves visitors does not need it.

Commands that never return (`queue:work`, `tinker`, `serve`, …) are refused
on `/_ops/command`.

### The visitor's hostname behind the edge

Scaleway routes a request on its Host header and only knows the container's
own domain, so the edge sends that Host and carries the real hostname in
`Cdn-Host`. The package puts it back, but only when the request also carries
the secret from `BREWLESS_EDGE_SECRET`, and only for the domains in
`BREWLESS_DOMAINS` (default: the host of `APP_URL`) and their subdomains.
Calling the container directly with a made-up hostname changes nothing.

## Settings

| Variable | Meaning |
| --- | --- |
| `BREWLESS_OPS_SECRET` | Turns the ops routes on, on this container |
| `BREWLESS_EDGE_SECRET` | The secret the edge adds to every request |
| `BREWLESS_DOMAINS` | Comma-separated domains that are yours |
| `BREWLESS_HEARTBEAT_STORE` | Cache store for the sign of life (`file`) |
| `RELEASE` | The running image; set by Brewless on each release |

Other release commands, queue time and tries:

```bash
php artisan vendor:publish --tag=brewless-config
```

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
