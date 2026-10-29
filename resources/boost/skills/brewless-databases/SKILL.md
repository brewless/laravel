---
name: brewless-databases
description: "Manages the data side of a Laravel application on Brewless: its database (Serverless SQL Database or Managed PostgreSQL/MySQL at Scaleway), migrations as release commands, queues (Scaleway Queues or database), file storage buckets, a bucket for backups, a Redis cache over TLS, the Private Network, and a database per tenant made at runtime with Brewless\\Laravel\\Databases\\DatabaseClient. Use when the user wants to add or change a database, cache, queue or bucket on Brewless, run or debug migrations, connect to the database, set up backups, or create a database per tenant in a multi-tenant application."
license: MIT
metadata:
  author: brewless
---

# Databases and resources on Brewless

Every resource stands in the customer's own Scaleway project. `brewless.yml` names what each environment uses; a deploy (or `brewless provision`) makes what is missing. Docs: https://docs.brewless.eu/applications/applications-and-environments

## Rules

- **What stands is never switched for something else.** Another database, region, queue or bucket in `brewless.yml` is refused before anything is made.
- **Removing a key never removes anything.** `false` lets a database, bucket or cache go from the environment while it keeps running at Scaleway. Deleting data is the user's own action in the Scaleway console.
- Check every change with `brewless plan <environment>` and ask before deploying it.

## In brewless.yml

```yaml
environments:
  production:
    database: shop-main        # by name; a new or existing one; false for none
    queue: sqs                 # sqs (Scaleway Queues), database, or false
    storage: shop-files        # a private bucket; FILESYSTEM_DISK=s3
    network: private           # Private Network for Managed databases and the cache
    cache:                     # a Managed Database for Redis, on the Private Network
      name: shop-cache
      size: RED1-micro
    backup:                    # a write-only bucket for backups, elsewhere
      region: nl-ams
      expire_days: 35
```

- **Region limits.**
  - Serverless SQL Database is only in `fr-par`.
  - Scaleway Queues is only in `fr-par` and `nl-ams`.
  - A cache is not offered in `it-mil`.
- **Database variables.** The database's `DB_*` variables are written into the environment's Secret Manager. Read them with `brewless env:pull <environment>`; never copy credentials into the repository.
- **Existing Managed Database.** It needs `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` from the user before the first deploy.

## Migrations

Migrations run as **release commands** on the release container before a release goes live: `migrate --force` by default, or the `release` list in `brewless.yml`. When they fail, nothing changes for visitors. Read why with:

```bash
brewless logs production --source=release
```

- Write migrations that the previous release can live with, because a rollback does not migrate back.
- `brewless command staging "migrate:status"` shows where a database stands.
- `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` are refused through Brewless on purpose.

## Serverless SQL Database

- It sleeps after about five minutes without a query, and wakes on the next one (the first query then waits). A worker or scheduler that queries it every minute keeps it awake.
- Use `queue: sqs` instead of `queue: database` for a sleeping database.
- It holds one database. Environments that share it share its tables.
- It uses `DB_SSLMODE`. Keep the connection's `sslmode` reading that variable.
- Scaleway restarts it for maintenance, by default on Tuesdays between 14:30 and 14:45 CEST, disconnecting clients for at most a minute. `brewless/laravel` tries a query again for up to `BREWLESS_DB_RETRY_SECONDS` (60) after a lost connection, outside transactions. Keep transactions short, and do not schedule releases in that window.
- Its sessions are shared: a connection pooler hands server sessions to whichever client asks, so a `SET` of one client (`search_path`, time zone) can reach another, and can be lost when the database scales. Never rely on a session setting.
- `pg_dump` empties the `search_path` of its session. A query of the application that lands there afterwards fails with `relation "…" does not exist` on a table that is there. Give a dump its own database user: an IAM application with the permission set `ServerlessSQLDatabaseReadOnly` in the project of the database, its ID as user name and an API key's secret as password, pushed as two variables with `brewless env:push` and handed to `pg_dump` as `PGUSER` and `PGPASSWORD`. The application's own `DB_USERNAME`/`DB_PASSWORD` do not do: the dump then shares the application's sessions again.

## Queues

- `queue: sqs` is the default and the choice to recommend: a job wakes the worker, which sleeps in between. `queue: database` has the worker called every minute to look for jobs, so it never sleeps and Scaleway bills it around the clock. Only choose it when the user is sure, and say what it costs.
- `queue: sqs` puts the queue key in `SQS_ACCESS_KEY_ID`, `SQS_SECRET_ACCESS_KEY` and `SQS_ENDPOINT`. `brewless/laravel` hands these to the `sqs` connection, so `AWS_*` stays free for the `s3` disk.
- Scaleway Queues refuses `Queue::size()`.

## Cache

A `cache` gives `CACHE_STORE=redis` and the `REDIS_*` variables, plus `BREWLESS_REDIS_CA`. With that certificate, `brewless/laravel` makes the `default` and `cache` redis connections use TLS and trust only that cluster. Keep `REDIS_CLIENT=phpredis`. A cache runs and is billed every hour; it does not sleep.

## Moving data in

- `brewless db:import <env> <postgres|mysql>://<user>@<host>[:<port>]/<database>` copies an existing database into the environment's own, in a job of the user's Scaleway project; the password comes from `SOURCE_DB_PASSWORD`, `--from-env=<old .env>` (its `DB_*` lines) or a prompt, never from the command line. It changes nothing at the source.
- PostgreSQL copies into PostgreSQL in one transaction; MySQL into a Managed MySQL as it is, or into Serverless SQL converted by pgloader (tables, indexes, foreign keys and sequences; not views, triggers or routines). PostgreSQL into MySQL is refused.
- It refuses a destination that holds tables. `--replace` removes them first: ask before using it, it replaces the environment's data. The first deploy runs the migrations, so copy before it or with `--replace` after it.
- `--dry-run` signs in to both sides and counts; `brewless db:import-status <env>` follows a copy. A source on a private network (private Vapor or Aurora databases) needs a tunnel and `--local`.
- On Vapor the `DB_*` values are not in `vapor env:pull`; they are on the database's page in the Vapor dashboard.
- After MySQL to PostgreSQL the application has to work on PostgreSQL: text comparisons are case-sensitive there. Try it on a test environment first.

## Backups

`backup` gives the application `BACKUP_BUCKET`, `BACKUP_REGION`, `BACKUP_ENDPOINT`, `BACKUP_ACCESS_KEY_ID` and `BACKUP_SECRET_ACCESS_KEY`. That key may **only put** objects (also in parts): it cannot read, list or delete. Write backups with it. Objects expire after `expire_days`. The key to restore is never in the application's variables.

## A database per tenant

On a Serverless SQL Database, `CREATE DATABASE` over SQL is never allowed. The application asks Brewless instead:

```yaml
environments:
  production:
    database: shop-main        # must be a Serverless SQL Database
    databases:
      prefix: shop-            # lowercase, digits, dashes; ends in a dash
```

```php
use Brewless\Laravel\Databases\DatabaseClient;
use Brewless\Laravel\Databases\DatabaseException;

$databases = resolve(DatabaseClient::class);

$database = $databases->create('shop-'.$tenant->id);        // being made
$database = $databases->waitUntilReady($database->name);    // polls every 2 s, 120 s at most

config(['database.connections.tenant' => [
    ...config('database.connections.pgsql'),
    'host' => $database->host,
    'port' => $database->port,
    'database' => $database->name,
]]);

$databases->find('shop-'.$tenant->id);    // null when Brewless did not make it
$databases->delete('shop-'.$tenant->id);  // removed at Scaleway now, with everything in it
```

- **Names.** A name starts with the prefix and holds lowercase letters, digits and dashes, at most 63. No underscores. Quote it in SQL (`"shop-1"`).
- **Credentials.** A tenant database signs in with the application's own `DB_USERNAME` and `DB_PASSWORD`; only the host differs. Creating its tables is the application's work.
- **Errors.**
  - `RequestRefused` (`->status`): a wrong key, a clock off by more than five minutes, or a name Brewless will not make.
  - `BrewlessUnreachable`: safe to retry.
  - `DatabaseNotReady`: waiting ended in an error or took too long.

  All extend `DatabaseException`.
- `$databases->configured()` says whether `BREWLESS_API_URL`, `BREWLESS_ENVIRONMENT` and `BREWLESS_DATABASES_KEY` are present. They arrive with the next deploy after `databases` is added.
- **Deleting is permanent.** Only call `delete()` where the application really ends a tenant, and never in a test against a real environment.
