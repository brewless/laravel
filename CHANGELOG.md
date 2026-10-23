# Changelog

Versions follow [semantic versioning](https://semver.org); before 1.0 a minor
version may change a route or a setting.

## 0.9.0 (23 October 2026)

- Guidelines and skills for coding agents, in `resources/boost`. Laravel Boost finds them by itself (`boost:install`, `boost:update`).
  - A guideline loaded in every session: what Brewless is, and the rules an agent keeps on it.
  - Four skills: `brewless-deployment`, `brewless-configuration`, `brewless-operations` and `brewless-databases`.
- Without Boost, `vendor:publish --tag=brewless-skills` puts the skills in `.agents/skills`, and `--tag=brewless-skills-claude` in `.claude/skills`.

## 0.8.2 (23 October 2026)

- An empty list of release commands (`release: []` in `brewless.yml`) runs nothing and lets the release go on, instead of being refused. For an application without a database there is nothing to run.

## 0.8.1 (22 October 2026)

- A database name is lowercase letters, digits and dashes (`shop-1`), no longer underscores: Scaleway refuses a Serverless SQL Database whose name has an underscore (`invalid_arguments`, `name:constraint`), so `create('shop_1')` reached Brewless and failed there. The client now refuses an underscore before anything is sent, as Brewless does. A name with an underscore that 0.7.0 accepted is refused; none could be made.
- A name followed by a line break is refused too.

## 0.8.0 (22 October 2026)

- With `BREWLESS_REDIS_CA` (the certificate of a Scaleway Managed Database for Redis, base64), the `default` and `cache` redis connections speak TLS and trust that certificate only. Brewless sets it with `cache` in `brewless.yml`. Without it, nothing changes.

## 0.7.0 (22 October 2026)

- `Brewless\Laravel\Databases\DatabaseClient` asks Brewless for a database per tenant of the application while it runs: `create()`, `find()` (null when Brewless did not make it), `delete()` (removed at Scaleway at once; one that is gone already is fine), `waitUntilReady()` (looks every two seconds, 120 seconds at most) and `configured()`. It answers with a `DatabaseInfo` (name, status, host, port).
- It reads `BREWLESS_API_URL`, `BREWLESS_ENVIRONMENT` and `BREWLESS_DATABASES_KEY` (`brewless.databases`), which Brewless sets with `databases` in `brewless.yml`. Every request is signed with an HMAC-SHA256 of the method, the path, the time and the hash of the body; the key itself is never sent and never in an exception.
- A request has 15 seconds, 5 to connect. A refusal is a `RequestRefused` with the HTTP status, an unreachable Brewless a `BrewlessUnreachable`, and a database that does not become ready a `DatabaseNotReady`; all three are a `DatabaseException`.
- The package only calls Brewless when the application asks the client for a database.

## 0.6.0 (2026-10-21)

- A queued job is signed with a key derived from the application key (`APP_KEY`), no longer with the ops secret. Brewless gives the ops secret only to the containers that do ops work, so every job the container that serves visitors queued (a mail at sign-in, for instance) went out unsigned and the worker dropped it. Every container of an application has the application key.
- After a rotation of `APP_KEY`, a job signed with one of `app.previous_keys` still runs.
- A job signed by 0.5.x no longer holds: let the queue run empty before updating, or accept that jobs queued just before the update are dropped.

## 0.5.3 (2026-10-21)

- The signature of a queued job covers its content, not the way its JSON was written: the keys of every object are sorted and escaping is written one way before it is signed and checked. A message that Scaleway Queues delivered with the same content written differently no longer gets dropped. A job signed by 0.5.2 or earlier no longer holds; none from Scaleway Queues did.
- A dropped job is logged with what was different: whether it carried a signature, its keys in order, its size, its content type and whether the provider wrote it again with another encoder. What the job carries is never logged.

## 0.5.2 (2026-10-21)

- A request with the ops secret may run for `ops.time_limit` seconds (900) instead of PHP's 30 (`max_execution_time` in php.ini-production). Release commands, a queue run and `/_ops/command` were cut off after 30 seconds and answered with a server error. A longer limit, or none, is left as it is; a request without the secret keeps PHP's own.

## 0.5.1 (2026-10-20)

- On the test address the URLs the application generates (assets, links, redirects) keep the test address instead of the host of `APP_URL`, so a page loads its CSS and scripts from its own origin and a strict Content-Security-Policy no longer blocks them.

## 0.5.0 (2026-10-20)

- A request on the test address Brewless gives an environment (a name under `brewless.dev`, or the domains in `BREWLESS_PREVIEW_DOMAINS`) is handled as a request for the host of `APP_URL`, so an application bound to its own domain answers there. Every answer on it carries `X-Robots-Tag: noindex, nofollow`, and `/robots.txt` there disallows everything.

## 0.4.2 (2026-10-16)

- 0.4.1 signed a job before naming its queue in it, so no job of 0.4.1 holds. Use 0.4.2.

## 0.4.1 (2026-10-16)

- The signature of a queued job covers its whole payload, also what other packages add to it, such as the tenant a job of stancl/tenancy runs for. A job signed by 0.4.0 no longer holds: let the queue run empty before updating.

## 0.4.0 (2026-10-16)

- Every job the `sqs` and `database` connections queue is signed with the ops secret (`brewless_signature`, over its id, handler and data, after the command is serialised), and names the queue it went on.
- A queue trigger of the provider posts a job to `/` without any secret. A job whose signature holds runs there like a queue worker runs it (tries, backoff, `failed()`, the failed jobs table); one that is tried again goes back on its queue as a new message, counting its attempts. The answer is always a success, also when the job failed or its signature did not hold (then it is dropped and a warning logged): a refused message would be delivered again every half minute, without end.
- `GET /_ops/status` names the moments of the schedule (`schedule`: the cron expression, time zone and task of every event), so the scheduler can be called only then instead of every minute.

## 0.3.0 (2026-10-16)

- `POST /_ops/migrate` runs the release commands sent along as `commands`, in order, instead of `ops.release_commands`, and names each of them in its answer under `commands`. Brewless sends the `release` commands of a project's `brewless.yml` this way. Commands that never end or throw data away are refused before any runs.
- The trigger and `POST /_ops/queue` take `queues`: the queues to work, the first first. A tick that finds no job due on them does not start a worker.

## 0.2.1 (2026-10-15)

- The ops routes work when the application's cache cannot be reached, such as a database cache whose table the release commands have yet to make. The secret alone decides then; guesses are counted again once the cache answers.

## 0.2.0 (2026-10-07)

- An address that keeps guessing the ops secret is shut out for a minute, with the same 404.
- Behind the edge the visitor's address is the entry the edge appended to `X-Forwarded-For`; without the edge secret the header is dropped. `BREWLESS_EDGE_HOPS` for more than one proxy.
- A tick that finds no job due does not start a worker.
- Commands that throw data away are refused on `/_ops/command` (`ops.destructive`).
- A `config/brewless.php` of your own only names what it changes.
- The `sqs` connection gets the key of a queue at Scaleway Queues (`SQS_ACCESS_KEY_ID`, `SQS_SECRET_ACCESS_KEY`, `SQS_ENDPOINT`).

## 0.1.0 (2026-10-07)

First version.

- The ops routes a Brewless environment calls on its own containers: release commands, scheduler tick, queue, queue status, retry of failed jobs, one console command.
- Without `BREWLESS_OPS_SECRET` none of these routes exist.
- `TrustEdgeHost`: the visitor's hostname behind the edge, believed only with the edge secret and only for your own domains.
