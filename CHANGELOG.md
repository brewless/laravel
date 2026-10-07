# Changelog

Versions follow [semantic versioning](https://semver.org); before 1.0 a minor
version may change a route or a setting.

## 0.1.0 (2026-10-07)

First version.

- The ops routes a Brewless environment calls on its own containers: release commands, scheduler tick, queue, queue status, retry of failed jobs, one console command.
- Without `BREWLESS_OPS_SECRET` none of these routes exist.
- `TrustEdgeHost`: the visitor's hostname behind the edge, believed only with the edge secret and only for your own domains.
