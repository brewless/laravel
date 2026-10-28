---
name: brewless-configuration
description: "Configures a Laravel application on Brewless: environment variables and secrets (brewless env:pull and env:push, Secret Manager), brewless.yml environments and container sizes, the PHP version, the BREWLESS_* variables of brewless/laravel, and the server files docker/Caddyfile, docker/php.ini and docker/entrypoint.sh. Use when the user wants to add or change an environment variable, APP_URL, a secret, brewless.yml settings, memory, CPU, timeouts, PHP settings, response headers, Cache-Control and the edge cache, or the FrankenPHP/Caddy configuration of a Brewless environment."
license: MIT
metadata:
  author: brewless
---

# Configuring a Brewless environment

Docs: https://docs.brewless.eu/running/variables, https://docs.brewless.eu/reference/brewless-yml and https://docs.brewless.eu/deployments/server-configuration

## Environment variables

The variables of an environment live in the customer's own Scaleway Secret Manager. Brewless keeps only their names and revisions, never a value.

```bash
brewless env:pull production      # writes .env.production, first line notes the revision
# edit .env.production
brewless env:push production      # replaces ALL variables of the environment with the file
```

- `env:push` replaces everything, comments included. Always pull first and edit that file; never push a file that was written from scratch. A push over a revision someone else saved is refused; the client names the variables it would remove and asks before it removes them.
- `--direct` reads or writes straight from this machine with the user's own Scaleway key (`SCW_SECRET_KEY` or the `scw` profile), so the values never pass through Brewless.
- **Never commit `.env.<environment>`**: make sure it is git-ignored. Do not print secret values back to the user unprompted.
- **A change reaches the containers with the next deploy.** Ask before you deploy.
- Ask before you run `env:push` on any environment: it is a change to a running system.

### Plain values in brewless.yml

Values that are not secret may live in `brewless.yml`. A deploy writes them into a new revision:

```yaml
environments:
  production:
    variables:
      APP_LOCALE: nl
      LOG_LEVEL: warning
```

A name that looks like a secret is refused here: one with `KEY`, `SECRET`, `PASSWORD`, `TOKEN`, `CREDENTIALS`, `PRIVATE`, `DSN`, `SALT` or `AUTH` as a word. A variable set in the file cannot be changed in the console or with `env:push`; change the file and deploy.

### Variables Brewless sets

Do not set these by hand; Brewless writes them on every deploy:
- `BREWLESS_OPS_SECRET` (release and worker containers only)
- `BREWLESS_EDGE_SECRET`
- `BREWLESS_DOMAINS`
- `RELEASE`
- with a cache: `BREWLESS_REDIS_CA`, `REDIS_*`
- with databases per tenant: `BREWLESS_API_URL`, `BREWLESS_ENVIRONMENT`, `BREWLESS_DATABASES_KEY`
- with Transactional Email: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `BREWLESS_MAIL_EVENTS_TOPIC`, `BREWLESS_MAIL_COPIES_*` (once; a value the user changed is kept)

`APP_URL` stays as the user set it. After a domain is added, `brewless domain:add` offers to set `APP_URL` to it.

## brewless.yml

`brewless.yml` sits in the project root and belongs in the repository. A key left out keeps what stands; an unknown key is refused by name. Check a change with `brewless plan <environment>` before deploying.

```yaml
organisation: acme
application: shop
framework: laravel
php: "8.4"                 # 8.3, 8.4 or 8.5
environments:
  production:
    kind: production       # production, staging or development
    region: fr-par         # fr-par, nl-ams, pl-waw, it-mil
    web:
      memory: 1024         # MB
      cpu: 560             # thousandths of a vCPU
      timeout: 60          # seconds per request
      min: 1               # 0 lets the container sleep
      max: 10
      concurrency: 50
    worker:
      queues: [default, mail]
    scheduler: true
```

- **Sizes.** Memory is 128 to 12288 MB and CPU 70 to 6000. Memory must be at least the CPU and at most 4 times it. Give one and the other follows Scaleway's pairing.
- **Timeouts** are 10 to 3600 seconds.
- Sizes and scale set in the file cannot be changed with `brewless scale` or in the console.
- **Mail.** A production environment with a domain gets Scaleway Transactional Email at its next deploy, unless the variables already have a `MAIL_MAILER` (never replaced) or the file says `mail: false`. `mail` is `scaleway`, `lettermint`, `false`, or a map with `mailer`, `domain` and `keep_days` (days a copy of each mail is kept in the environment's own bucket, 0 to 30). Do not put `MAIL_MAILER` under `variables` next to `mail`: that is refused. The DNS records are written only after the user says yes (`brewless deploy --mail-records`); never pass that flag on their behalf. Bounces reach the app as `Brewless\Laravel\Mail\MailEvent`. See https://docs.brewless.eu/running/mail.
- **Firewall.** With bunny.net in front, `firewall` puts bunny.net Shield on the pull zone: `rate_limit` is the requests one address may make in 10 seconds before it is blocked for a minute (Shield Basic, free), `bot_control` is `challenge`, `log` or `off`. Bot control takes Shield Advanced or higher, which costs money at bunny.net: ask the user before adding it, and never change the Shield plan yourself. `firewall: false` removes what Brewless set. See https://docs.brewless.eu/running/firewall.

## The edge cache

The web container sleeps after 15 idle minutes; every request that reaches it starts the count again. `brewless/laravel` (`Http\Middleware\CacheAtEdge`) therefore gives an answer `Cache-Control: public, max-age=0, s-maxage=<BREWLESS_EDGE_CACHE>` (3600 seconds, `0` turns it off) when it cannot depend on who asked: a GET or HEAD without cookies and without `Authorization`, not for `/_ops/*`, answered with a 200, 301, 308, 404 or 410, without a cookie, without a started session, without a `Cache-Control` the application set and without a `Vary` other than `Accept-Encoding`, and on a page that did not ask an auth guard who is signed in (`@auth`, `auth()->user()`). bunny.net then answers the next request for that address itself. Every release empties the edge, and `max-age=0` keeps the page out of browsers. A pull zone Brewless manages keeps a page per hostname; an edge the user set up themselves must vary its cache by hostname too, or a subdomain gets the page of the domain (set `BREWLESS_EDGE_CACHE=0` until it does).

- **Sessions on public pages.** From version 0.18 a guest without any cookie gets no session (no session cookie, no `XSRF-TOKEN`, nothing stored) on a page that puts nothing in the session and whose answer does not contain the CSRF token; that page can be kept. A page with a form, or one that calls `session()->put()` or flashes, keeps its session. `Route::withoutSession(fn () => ...)` or `->withoutSession()` on a route takes the session, its errors and the CSRF check off for everybody; use it for pages that show the token (a meta tag, Inertia, Livewire) but need no session. Only HTML pages, and by itself only on Laravel 13 (its CSRF check accepts a same-origin request without a token; on Laravel 12 an Inertia or Vue page that posts on the `XSRF-TOKEN` cookie would get a 419): `BREWLESS_STATELESS_GUESTS=true` turns it on on Laravel 12, `false` turns it off. Before 0.18, take the middleware off by hand: `->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])` (`ValidateCsrfToken` on Laravel 12).
- **Keep a page out** by setting `Cache-Control` yourself: `->middleware('cache.headers:private')`, or `no-store`. Do that when the answer depends on what the edge does not see: the visitor's address, a header of your own, or `Accept` (the edge keeps one answer per address and ignores `Vary`).
- **Check it** after a deploy with `curl -sI https://<domain>/<page>` twice: `cdn-cache: HIT` on the second answer. See https://docs.brewless.eu/running/edge-cache.

## Server files

Commit any of these and the next build puts it in the image in place of the default:

| File | Replaces |
| --- | --- |
| `docker/Caddyfile` | The FrankenPHP/Caddy configuration |
| `docker/php.ini` | Brewless's PHP settings (`conf.d/zz-brewless.ini`) |
| `docker/entrypoint.sh` | The start script |

Each replaces its default **as a whole**: copy the default from the docs and change what you need. A file that is a symbolic link is not taken.

### What a Caddyfile has to keep

- `:{$PORT:8080}` as the address.
- `auto_https off` in the global block.
- `frankenphp` and `php_server`.
- `root * /app/public`.

### What a Caddyfile must not do

- Answer `/` or `/_ops/...` itself.
- Redirect every hostname but one, or put a password on the whole site.
- Declare a `worker`: FrankenPHP worker mode and Octane are refused at build time.

Put new directives inside the `:{$PORT:8080}` block, before `php_server`.

### php.ini

Keep `variables_order = EGPCS` (otherwise `$_ENV` stays empty) and `opcache.validate_timestamps = 0`. When raising `memory_limit`, raise the container's `web.memory` in `brewless.yml` too: up to `concurrency` requests share it.

## Package settings

`php artisan vendor:publish --tag=brewless-config` publishes `config/brewless.php`. It holds the default release commands, the queue time and tries, and the commands to refuse. It only has to name what it changes.
