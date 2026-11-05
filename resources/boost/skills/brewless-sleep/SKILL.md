---
name: brewless-sleep
description: "Finds out what keeps a Brewless container awake and what to do about it: php artisan brewless:sleep, the rules the edge cache follows, sessions on public pages, files without a version, and schedules that tick too often. Use when a container never scales to zero, when costs are higher than expected for a quiet site, when a page should come from the edge and does not, or before putting a new application live."
license: MIT
metadata:
  author: brewless
---

# Letting a container sleep

A Brewless container is serverless: Scaleway stops it after 15 minutes without a request, and every request that reaches it starts those 15 minutes again. So what decides whether it ever sleeps is not how busy the site is, but how much of it the edge answers by itself. **One request a minute is enough to keep a container running day and night.** Docs: https://docs.brewless.eu/running/edge-cache

## Start here

```bash
php artisan brewless:sleep                       # every public page, with the edge's own verdict
php artisan brewless:sleep --path=/ --path=/pricing
php artisan brewless:sleep --strict              # fails when a page cannot be kept; for a hook or a pipeline
```

It asks the application rather than reading it: each page is fetched through the application's own middleware, without cookies, as a first visitor would, and the answer is held against the rules of `CacheAtEdge`. Nothing leaves the machine and no container is woken.

**Run it where it counts.** A page can answer differently on a developer machine than it does live, and only the live answer decides what the edge keeps. The command says which environment answered; for production:

```bash
brewless command production "brewless:sleep"
```

## What it reports, and what to do

**"it keeps a session"** — the commonest one. A page that shows a CSRF token, or puts anything in the session, gets a session cookie, and the edge never keeps a page that carries a cookie. Take the token off pages that have no form; a `<meta name="csrf-token">` in a layout puts one on every page of the site. `BREWLESS_STATELESS_GUESTS` must not be `false`. Pages that genuinely need a session (a cart, a wizard) are meant to wake the container; leave them.

**"it sets Cache-Control itself"** — Brewless leaves an answer alone when the application chose its own `Cache-Control`, so a middleware or controller that sets one silently overrules `edge.cache` in `brewless.yml`. Check the shape: the package writes `public, max-age=0, s-maxage=<seconds>`; anything else is the application's. Unless the page truly needs its own rule, take it out and let `edge.cache` decide — one setting in one place.

**"it sets a cookie (…)"** — analytics, a consent choice, a locale. Set it from the browser instead, or on the one request that needs it, not on every page.

**"the answer is a 302"** — only 200, 301, 308, 404 and 410 are kept. A redirect on a hot address (a trailing slash, an old URL) is worth making a 301.

**"it varies on …"** — the edge keeps one answer per address and understands no `Vary` but `Accept-Encoding`. A page that varies on `Accept-Language` or a header needs a URL per variant.

**"it looked at who is signed in"** — `@auth`, `auth()->user()` or the `auth` middleware on a page everyone else sees the same. Split the page, or render the signed-in part from the browser.

**"files your pages name have no `?v=`"** — the pull zone serves a file from the storage zone only when its address carries a version. Without one, the edge fetches it from the container, and bunny.net's Origin Shield drops a file nobody asks for within hours whatever its cache time. Give such files an address with `?v=`, or put large media (video, photography) in a bucket and serve it from the files address.

**A schedule that repeats within the hour** — every tick wakes the worker container. Daily or hourly is almost always enough. Do not poll for alarms: let the thing that fails report itself.

**"edge.cache is 0"** — nothing is kept at all and every visitor reaches the container. Set `edge.cache` in `brewless.yml`.

## What the command cannot see

- **An uptime monitor or a health check from outside.** Brewless never asks the application anything for this reason; a monitor that polls every minute undoes all of the above by itself.
- **Addresses with a query string.** The edge keeps one copy per address, so `?utm_source=…` and `?gclid=…` are each their own copy and each a first visit.
- **What visitors really ask for.** The console shows the live figures per environment: requests, how many reached the container, and the share of time it was scaled to zero.

## Reading the figures afterwards

Scale-to-zero and cold starts are in the console under the environment, read from the provider's own measurements; nothing is asked of the application for them. Judge a change there a day later, not on the hour you made it: a release empties the edge, so the hours after a deploy always look worse than a quiet day.
