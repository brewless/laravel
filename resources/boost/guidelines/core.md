## Brewless

This application is deployed with [Brewless](https://brewless.eu): serverless containers at Scaleway, behind bunny.net, in the customer's own cloud accounts. `brewless/laravel` gives its containers the routes Brewless releases and runs them with. The `brewless` command line client (`composer global require brewless/cli`) deploys and inspects it. `brewless.yml` in the project root says how each environment should be. The documentation is at https://docs.brewless.eu.

### Rules

- **Ask before anything that changes an environment.** Never run `brewless deploy`, `rollback`, `env:push`, `scale`, `provision`, `domain:add`, `dns:add`, `dns:update`, `dns:remove`, `service:push` or `detach`, or `brewless command` on production, unless the user asked for that action on that environment. `brewless plan`, `releases`, `logs` and `env:pull` only read.
- **Never pass `--force`** to skip a confirmation the user has not given.
- **Never put a secret in `brewless.yml` or in the repository.** Plain values go under `variables` in `brewless.yml`. Secrets go into the environment's own Secret Manager with `brewless env:push`. Brewless refuses a name in `brewless.yml` that looks like a secret.
- **The containers are serverless.** Nothing stays running. The local disk does not outlive the container, and is not shared between replicas. Keep uploads in a bucket (`storage` in `brewless.yml`), sessions and cache in the database or the cache, and never rely on a file written in an earlier request.
- **No FrankenPHP worker mode and no Laravel Octane.** Brewless runs FrankenPHP in classic mode and refuses a build that declares a worker in `docker/Caddyfile` or starts Octane in `docker/entrypoint.sh`.
- **`/_ops/*` and `POST /` belong to the package.** Do not define routes, middleware redirects or host checks that catch them. The release commands, the scheduler and the queue arrive there.
- **Public pages are kept at the edge.** `brewless/laravel` lets bunny.net keep, for `BREWLESS_EDGE_CACHE` seconds (300), every GET without cookies or `Authorization` that is answered without a cookie, a session or a `Cache-Control` of the application's own; every release empties the edge. A page that needs no session should not have one: take `StartSession`, `ShareErrorsFromSession` and the CSRF middleware off its route, or it wakes the container on every visit. A page that depends on something the edge cannot see (the visitor's address, a header, `Accept`) sets `Cache-Control: private` or `no-store` itself.
- **Migrations run as release commands** before a release goes live (`migrate --force` by default, or `release` in `brewless.yml`), never by hand in a container.
- A variable change reaches the containers with the **next deploy**, not before.

Use the `brewless-deployment`, `brewless-configuration`, `brewless-operations` and `brewless-databases` skills for the details.
