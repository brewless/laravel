# Changelog

Versions follow [semantic versioning](https://semver.org); before 1.0 a minor
version may change a route or a setting.

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
