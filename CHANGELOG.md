# Changelog

Versions follow [semantic versioning](https://semver.org); before 1.0 a minor
version may change a route or a setting.

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
