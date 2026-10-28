# brewless/laravel

What a Laravel application needs to run on [Brewless](https://brewless.eu).
It runs in **your** containers, in your own cloud account, and keeps working
when you stop using Brewless. It calls Brewless only when your application
asks it for a database per tenant (see below); otherwise it sends nothing
anywhere.

```bash
composer require brewless/laravel
```

There is nothing to register. Laravel 12 and 13, PHP 8.3 and up. The AWS SDK
(`aws/aws-sdk-php`) comes along: Laravel's `sqs` connection needs it, and a
queue at Scaleway Queues is where Brewless puts the jobs of a new environment.

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

A signed job also carries the moment it was signed and its attempts, so a
message that was seen once cannot be posted again at will. It is good for
`ops.job_max_age` seconds (`BREWLESS_JOB_MAX_AGE`, 3600) and dropped after
that, or when it was signed more than a minute ahead of the container's
clock. Within that time each delivery runs once: one that comes again is
dropped, and one that is still being handled by another request gets a 409,
so the provider tries it again later (a delivery whose container stopped
before it answered runs again after `ops.time_limit`). A job that goes back
on its queue, and a failed job that is retried, is signed again and runs as
a delivery of its own. The marks are kept in the application's default
cache; give the containers a cache they share (redis, the database), or a
delivery to another container is not recognised. Without a cache that
answers, a delivery runs.

A failure is answered with `ok: false` and reported to the application's
log, never with its reason; a release command that fails is named, so
Brewless can say which one stopped the release.

Commands that never return (`queue:work`, `tinker`, `serve`, …) and commands
that throw data away (`migrate:fresh`, `db:wipe`, …) are refused on
`/_ops/command`, and as release commands sent along with a release.
Brewless sends the release commands and queues of your project's
`brewless.yml`; without them the commands of `ops.release_commands` and the
default queue are used. Every wrong secret gets the same 404 and is counted
per address; an address that gives a wrong secret ten times in a minute is
logged. It is not shut out: the right secret, or a job whose signature holds,
always passes, so someone guessing behind the same address as Brewless (the
provider's front end) cannot lock Brewless out. What keeps the secret from
being found by trying is its length. A tick that finds no job
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
proxies append to the header (default 1). Without an edge secret there is no
edge, but there is the provider's own front end, which appends the address it
saw as the last entry: only that entry is kept, so a visitor cannot choose
their own address there either.

### Pages the edge keeps

A serverless container sleeps only after a quiet spell (15 minutes at
Scaleway), and every request that reaches it starts the count again. Behind
an edge that keeps nothing, every visitor and every crawler wakes it, so a
public site hardly ever sleeps. So an answer that cannot depend on who asked
gets `Cache-Control: public, max-age=0, s-maxage=300`, and the edge (bunny.net)
answers the next request for that address itself. That is the case when:

- the request is a GET or HEAD without cookies and without `Authorization`,
  and not for `/_ops/*`;
- the answer is a 200, 301, 308, 404 or 410, sets no cookie, and started no
  session;
- the application did not set `Cache-Control` itself;
- it varies on nothing but `Accept-Encoding`.

`max-age=0` keeps it out of the browser: Brewless empties the edge on every
release, so after a deploy nobody gets a page of the release before. A pull
zone Brewless manages keeps a page per hostname, so a subdomain never gets the
page of the domain. An edge of your own must do the same (at bunny.net: vary
the cache by hostname), or turn this off.
`BREWLESS_EDGE_CACHE` sets the seconds (`300`); `0` turns it off.

A route in Laravel's `web` group starts a session and sets a cookie, so it is
never kept. To let the edge keep a page that needs no session, take the
session off its route:

```php
Route::get('/pricing', PricingController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class]);
```

Keep a page out of the edge by setting `Cache-Control` yourself, for instance
with `->middleware('cache.headers:private')` or `no-store`. Do that for a page
that depends on something the edge cannot see: the visitor's address, a header
of your own, or the `Accept` header (the edge keeps one answer per address).

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

On the test address the session cookie gets a `__Host-` prefix (and is
Secure, for the whole path, without a domain). Every environment's test
address sits under the same domain; a browser never lets one of them set or
overwrite a `__Host-` cookie of another, so an application on a neighbouring
test address cannot put a session of its own on yours. On your own domains the
session cookie keeps its name and settings.

### A cache over TLS

A cache at Scaleway (`cache` in `brewless.yml`) is a Managed Database for Redis on the Private Network of your project, reached over TLS with a certificate of its own cluster. Brewless gives the application that certificate as `BREWLESS_REDIS_CA` (base64), next to `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME` and `REDIS_PASSWORD`. With it, the package makes the `default` and `cache` redis connections speak TLS and trust that certificate and no other (`brewless.redis.connections` names them). The host is an address on the Private Network, which no certificate names, so the name is not checked; the certificate is. Without the variable your redis connections are left as you configured them.

### Through a database restart

A managed database is sometimes restarted: Scaleway's Serverless SQL Database
disconnects its clients for at most a minute during maintenance, by default on
Tuesdays between 14:30 and 14:45 CEST. Laravel reconnects and runs a query once
when its connection went away, at once, which is too early then. The package
gives the `pgsql` and `mysql` drivers a connection that tries the query again
with pauses of 1, 2, 4 and then 8 seconds, for up to `BREWLESS_DB_RETRY_SECONDS`
(60). Never inside a transaction, as in Laravel: what ran before in it went
with the connection. An application with a connection class of its own for a
driver keeps it.

### A cache in cluster mode

With `cache: {name: …, nodes: 3}` in `brewless.yml` the cache is a cluster of
three to six nodes, and Brewless sets `BREWLESS_REDIS_NODES` to every node
(`host:port`, comma-separated). The package then makes the `default` and
`cache` redis connections a phpredis cluster of those nodes, with the
connection's own user and password and the cluster's certificate over TLS, so
`Cache` and `Redis` work as before. A redis connection of your own is left as
it is.

### A database per tenant

An application that gives every customer of its own a database of its own
makes that database while it runs. On a Serverless SQL Database at Scaleway
`CREATE DATABASE` over SQL is never allowed: each database is a resource of
its own, with its own host, made through Scaleway's API. With `databases` in
`brewless.yml` the application asks Brewless instead:

```yaml
environments:
  production:
    database: shop-main
    databases:
      prefix: shop-
```

Brewless then gives every container `BREWLESS_API_URL`,
`BREWLESS_ENVIRONMENT` and `BREWLESS_DATABASES_KEY`, and the client signs
each request with that key:

```php
use Brewless\Laravel\Databases\DatabaseClient;

$databases = resolve(DatabaseClient::class);

$database = $databases->create('shop-'.$tenant->id);      // being made
$database = $databases->waitUntilReady($database->name);  // looks every 2 s, 120 s at most

config(['database.connections.tenant' => [
    ...config('database.connections.pgsql'),
    'host' => $database->host,
    'port' => $database->port,
    'database' => $database->name,
]]);

$databases->find('shop-'.$tenant->id);    // null when Brewless did not make it
$databases->delete('shop-'.$tenant->id);  // removed at Scaleway now, with everything in it
```

A new database signs in with the application's own `DB_USERNAME` and
`DB_PASSWORD`; only the host differs. The name has lowercase letters, digits
and dashes, at most 63, and starts with the prefix: a Serverless SQL Database
takes no underscore in its name, and the database inside has the same name.
Quote it in SQL (`"shop-1"`), since PostgreSQL reads a bare dash as a minus.
`configured()` says whether the three variables are there.

Every request carries `X-Brewless-Environment`, `X-Brewless-Timestamp` and
`X-Brewless-Signature`: an HMAC-SHA256 with the key, as it stands in the
variable, over the method, the path, the time in seconds and the SHA-256 of
the (empty) body, each on a line of its own. The key itself is never sent,
and never in an exception. A request has `databases.timeout` seconds (15),
5 of them to connect.

What can go wrong is a `Brewless\Laravel\Databases\DatabaseException`:
`RequestRefused` when Brewless says no (a wrong key or a clock more than five
minutes off, a name it does not make; `->status` is the HTTP status),
`BrewlessUnreachable` when Brewless or Scaleway cannot be reached or asks to
slow down (safe to ask again), and `DatabaseNotReady` when waiting ends in an
error or takes too long.

### Mail

With Scaleway Transactional Email as the mailer (`mail` in `brewless.yml`, the default of a production environment with a domain), the package does two things, each only when Brewless gave the container its variables:

- **A copy of every mail sent**, headers and content as it went out, goes to a private bucket of the environment (`BREWLESS_MAIL_COPIES_*`), under the id Transactional Email queued it as, for the Brewless console to show. Object Storage removes it after `mail.keep_days`. Storing a copy never stops a mail: a failure is logged.
- **`POST /_ops/mail-events`** takes what Transactional Email reports of a mail from the topic Brewless subscribed (`BREWLESS_MAIL_EVENTS_TOPIC`), only with Scaleway's signature, confirms the subscription, and fires `Brewless\Laravel\Mail\MailEvent`:

```php
use Brewless\Laravel\Mail\MailEvent;
use Illuminate\Support\Facades\Event;

Event::listen(MailEvent::class, function (MailEvent $event): void {
    if ($event->bounced() || $event->complained()) {
        // $event->to, $event->type ('email_mailbox_not_found', 'email_spam', …), $event->error
    }
});
```

## For coding agents

The package teaches a coding agent (Claude Code, Codex, Cursor, Copilot, Junie and others) how an application on Brewless is deployed and run.

With [Laravel Boost](https://laravel.com/docs/boost) there is nothing to do. Run `php artisan boost:install`, or `php artisan boost:update` after updating, and pick `brewless/laravel` among the third-party packages. Boost then writes the files below where each of your agents reads them:

| What | Where it comes from | What it does |
| --- | --- | --- |
| Guideline | `resources/boost/guidelines/core.md` | Loaded in every session: what Brewless is, and the rules an agent never breaks on it |
| `brewless-deployment` | `resources/boost/skills/` | Deploys, plans, releases, rollbacks, pipelines, and why a release failed |
| `brewless-configuration` | `resources/boost/skills/` | Variables and secrets, `brewless.yml`, container sizes, PHP version, `docker/` server files |
| `brewless-operations` | `resources/boost/skills/` | Logs, running a command, queues and the schedule, scaling, domains and DNS |
| `brewless-databases` | `resources/boost/skills/` | Databases, migrations, queues, buckets, backups, the cache, and a database per tenant |

The guideline goes into your `AGENTS.md` or `CLAUDE.md`. The skills go into `.agents/skills`, `.claude/skills`, `.cursor/skills` and so on, one folder per agent. A skill is loaded only when the task calls for it.

The rules of the guideline:
- Ask before anything that changes an environment: a deploy, a rollback, `env:push`, scaling, DNS, or a command on production.
- Never pass `--force` on the user's behalf.
- Never put a secret in `brewless.yml` or the repository.
- Do not rely on the local disk.
- No FrankenPHP worker mode or Octane.
- Leave `/_ops/*` and `POST /` to the package.
- Let public pages go without a session, so the edge can keep them; set `Cache-Control` yourself on a page the edge must not keep.

Reading (`plan`, `releases`, `logs`, `env:pull`) an agent may do by itself.

Without Boost, publish the skills to where your agent looks for them:

```bash
php artisan vendor:publish --tag=brewless-skills          # .agents/skills (Codex, Amp, OpenCode, Zed, ...)
php artisan vendor:publish --tag=brewless-skills-claude   # .claude/skills (Claude Code)
```

The skills follow the [Agent Skills](https://agentskills.io) format: a folder per skill with a `SKILL.md`.

## Settings

| Variable | Meaning |
| --- | --- |
| `BREWLESS_OPS_SECRET` | Turns the ops routes on, on this container |
| `SCHEDULE_TIMEZONE` | The time zone of the schedule (`Europe/Amsterdam`), unless the application sets `app.schedule_timezone` itself |
| `BREWLESS_EDGE_SECRET` | The secret the edge adds to every request |
| `BREWLESS_DOMAINS` | Comma-separated domains that are yours |
| `BREWLESS_EDGE_HOPS` | Proxies between the visitor and the container (`1`) |
| `BREWLESS_EDGE_CACHE` | Seconds the edge keeps an answer that is the same for everybody (`300`); `0` keeps nothing |
| `BREWLESS_PREVIEW_DOMAINS` | Comma-separated test domains (`brewless.dev`); empty turns the test address off |
| `BREWLESS_HEARTBEAT_STORE` | Cache store for the sign of life (`file`) |
| `BREWLESS_JOB_MAX_AGE` | Seconds a job signed for a queue trigger is good for (`3600`) |
| `BREWLESS_REDIS_NODES` | The nodes of a cache in cluster mode, `host:port` comma-separated; the redis connections then become a cluster |
| `BREWLESS_REDIS_CA` | The certificate of the cache's cluster, base64; the redis connections then speak TLS and trust only that certificate |
| `SQS_ACCESS_KEY_ID`, `SQS_SECRET_ACCESS_KEY`, `SQS_ENDPOINT` | The key and address of a queue at Scaleway Queues; handed to the `sqs` connection, which otherwise shares `AWS_*` with the `s3` disk |
| `BREWLESS_API_URL`, `BREWLESS_ENVIRONMENT`, `BREWLESS_DATABASES_KEY` | Where and as which environment to ask Brewless for a database per tenant, and the key to sign with; set by Brewless with `databases` in `brewless.yml` |
| `BREWLESS_DB_RETRY_SECONDS` | How long a query whose connection went away is tried again (`60`); `0` keeps Laravel's single try |
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
