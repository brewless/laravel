---
name: brewless-operations
description: "Inspects and operates a running Laravel application on Brewless: reading logs (brewless logs, per container or database, --tail, --search), running an artisan command in an environment (brewless command), checking status and releases, queues, failed jobs and the schedule, scaling and scale-to-zero, domains and DNS records. Use when the user asks what is happening on staging or production, wants to see errors or logs, run a command on a Brewless environment, debug a queue or scheduled task, scale, or add a domain or DNS record."
license: MIT
metadata:
  author: brewless
---

# Operating a Brewless environment

Everything runs in the customer's own Scaleway project: a web container, a release container and a worker container per environment, scaled to zero when idle unless `web.min` is above 0. Brewless reads logs and figures live from Scaleway and stores none of them. Docs: https://docs.brewless.eu/running/logs

## Reading what happens (read-only, safe)

```bash
brewless releases production                          # latest releases and their status
brewless logs production                              # web container, last hour
brewless logs production --source=release             # where failed release commands and migrations log
brewless logs production --source=worker              # queue and schedule
brewless logs production --source=shop-main           # a database, by its name
brewless logs production --range=day --search="SQLSTATE"
brewless logs production --tail                       # follow until stopped
```

- `--range` is `hour`, `day` or `week`.
- An unknown `--source` is refused with the names the environment does have.
- A long period can hold more lines than are shown: narrow `--range` or add `--search`.
- Start with the container that did the work: `release` for migrations, `worker` for jobs and scheduled tasks, `web` for requests.

## Running a command

```bash
brewless command staging "migrate:status"
brewless command production "cache:clear"     # asks for confirmation on production
```

- Give the artisan command without `php artisan`, on one line. It runs in the environment's own container on the live release and has **50 seconds**. Longer work belongs in a queued job.
- **Ask the user before you run anything on production**, and never add `--force` yourself.
- Prefer read-only commands such as `migrate:status`, `about` or `schedule:list` when investigating.
- These are refused:
  - commands that never end: `tinker`, `serve`, `queue:work`, `queue:listen`, `schedule:work`, `pail`, `horizon`, `reverb:start`, `octane:start`, `dev`;
  - commands that throw data away: `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`.
- Brewless keeps the command's name in the audit trail, never its arguments or output.

## Queues and the schedule

- The worker container works `worker.queues` from `brewless.yml` (default `default`). It also runs the schedule unless `scheduler: false`.
- Nothing stays running, so something calls the worker:
  - **Jobs at Scaleway Queues** (`queue: sqs`): the queue calls the worker for each job. The scheduler is called by crons at the moments the schedule has work. A task added to the schedule gets its cron with the **next deploy**.
  - **Jobs in the database** (`queue: database`): a trigger calls the worker every minute. This keeps a serverless database awake.
- Give a worker that runs long jobs a `worker.timeout` in `brewless.yml` above the longest job.
- **Failed jobs** stay in the application's own `failed_jobs` table; Brewless never reads why a job failed. They can be put back on the queue from the console (Queues tab).
- A job queued by the application is signed by `brewless/laravel`. Do not replace the `sqs` or `database` queue connectors.

## Scaling

```bash
brewless scale staging --min=0 --max=2      # 0 lets it sleep; the first request then waits for a start
brewless scale production --min=1 --max=10
```

- Bounds set in `brewless.yml` (`web.min`, `web.max`) are refused here: change the file and deploy.
- Ask before scaling production.

## Domains and DNS

```bash
brewless domain:add production shop.example.eu    # answer on a domain, with a certificate
brewless dns:list                                 # zones Brewless manages
brewless dns:list example.eu                      # records with their ids
brewless dns:add example.eu TXT _verify "value" --ttl=300
```

- Adding a domain can move its visitors, and DNS changes are live for everyone: **ask first**.
- Every environment also has a test address `<organisation>-<application>-<environment>.brewless.dev`. It is marked `noindex`.
- `domains` in `brewless.yml` lists the domains an environment should answer on. A deploy names the missing ones and adds them after a yes, or with `--domains`.
