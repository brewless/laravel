---
name: brewless-deployment
description: "Deploys a Laravel application with Brewless and follows its releases: brewless deploy, plan, releases and rollback, the build and release steps, brewless.yml build and release commands, deploying from a pipeline with BREWLESS_API_TOKEN, and why a release failed. Use when the user wants to deploy, ship, release, roll back, set up CI/CD for Brewless, or asks why a Brewless deploy or release failed."
license: MIT
metadata:
  author: brewless
---

# Deploying with Brewless

Brewless builds the current commit into an image in the customer's own Scaleway registry and runs the release commands against it. It then switches the web container, the workers and the edge to it. The `brewless` client does this from the project root, where `brewless.yml` names the organisation and the application. Docs: https://docs.brewless.eu/deployments/deployments

## Before you deploy

- **Deploy only when the user asked for it, and only to the environment they named.** `brewless deploy` without an environment uses `default-environment` from `brewless.yml`: name the environment explicitly.
- **Only what is committed is deployed.** Uncommitted changes stay on the machine. Check `git status` and say so when there are changes that will not go along.
- Run `brewless plan <environment>` first when `brewless.yml` changed. It changes nothing, and shows per key:
  - `=` the same;
  - `~` changes;
  - `+` added;
  - `·` kept because the file leaves it out;
  - `!` refused, with the reason;
  - `?` could not be read.

## Commands

```bash
brewless plan production              # what brewless.yml asks next to what stands; changes nothing
brewless deploy production            # build, release commands, go live; follows it to the end
brewless deploy production --domains  # also add the domains of brewless.yml without asking
brewless releases production          # the latest releases and their status
brewless rollback production          # the release before the live one goes live again (asks first)
brewless rollback production --release=182
```

A deploy first does what `brewless.yml` asks of the environment's resources:
- it makes a missing environment, and adds a database, queue, bucket, cache or network it lacks;
- it never switches what stands for something else: another region, database or queue is refused before anything is made;
- removing a key from the file never removes anything.

## The steps of a release

1. **Source.** The commit is packed and uploaded to a private bucket in the customer's project.
2. **Build.** A Serverless Job runs `composer install --no-dev` and `npm ci && npm run build`, then the `build` commands of `brewless.yml`. It builds for the `php` version of `brewless.yml` (default 8.4).
3. **Migrate.** The release container runs the release commands (`migrate --force` by default, or `release` in `brewless.yml`) before anything changes for visitors.
4. **Activate.** The web container switches to the new image and has to answer healthy on `/up`. A redirect counts as unhealthy.
5. **Workers, edge, built files.** The worker and schedule follow, and the bunny.net edge forgets the pages of the previous image.

One release at a time per environment. A failed release never goes live: the previous release keeps serving.

## brewless.yml for the build and release

```yaml
organisation: acme
application: shop
framework: laravel
php: "8.4"
default-environment: staging
build:
  - npm run docs          # after the framework's own build steps
release:
  - migrate --force       # artisan commands, without "php artisan"
  - cache:clear
```

- `release: []` runs no release commands. This needs `brewless/laravel` 0.8.2 or later.
- A release command that never ends or throws data away is refused: `queue:work`, `tinker`, `migrate:fresh`, `db:wipe`, and the like.
- Release commands get 90 seconds per release.

## Release statuses

`queued`, `building`, `migrating` and `activating` are under way. `active` is live and `superseded` was live once. `failed` and `cancelled` never served visitors. You can only roll back to `active` or `superseded`.

## When a release fails

- **Build failed.** The client prints the last lines of the build log. Fix the dependency or build error locally, commit and deploy again.
- **The release commands did not run.** The application did not answer `ok` on `/_ops/migrate`. Check three things:
  - `brewless/laravel` is installed;
  - `BREWLESS_OPS_SECRET` reaches the app;
  - no middleware of the application redirects, checks the host of, or blocks `/_ops/*`.
- **A 500 before the release commands.** Read `brewless logs <environment> --source=release`. A missing `DB_USERNAME` or `DB_PASSWORD` is common.
- **Unhealthy.** `/up` answered something other than 2xx on the container's own address. A 404 usually means `APP_URL`/`BREWLESS_DOMAINS` do not name the host. Read `brewless logs <environment>`.
- **Missing variables.** The deploy is refused before the build and names the variables. Add them with `brewless env:pull`, edit, then `brewless env:push` (see the `brewless-configuration` skill).

## Rollbacks

A rollback puts an earlier image live as a new release, with the variables revision and `brewless.yml` settings that release had. **The database is not migrated back**: the old code has to work with the newer schema. Ask before rolling back production.

## From a pipeline

A pipeline deploys with a pipeline token made in the console (Account › Pipeline tokens). It starts with `bl_` and acts as the person who made it.

```yaml
# .github/workflows/deploy.yml
- run: composer global require brewless/cli
- run: brewless deploy production --no-interaction
  env:
    BREWLESS_API_TOKEN: ${{ secrets.BREWLESS_API_TOKEN }}
```

Keep the token in the pipeline's secrets, never in the repository. The client exits non-zero when the deploy fails.
