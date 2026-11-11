<?php

declare(strict_types=1);

namespace Brewless\Laravel\Console;

use Brewless\Laravel\Http\Middleware\StartSessionWhenNeeded;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Says what keeps this application's container awake.
 *
 * A serverless container sleeps only after a quiet spell (15 minutes at
 * Scaleway), and every request that reaches it starts that spell again. So
 * what decides whether it ever sleeps is not how busy the site is, but how
 * much of it the edge can answer by itself. One request a minute is enough to
 * keep a container running all day.
 *
 * This asks the application rather than reading it: every page is fetched
 * through the application's own middleware, without cookies, as a first
 * visitor would, and the answer is held against the rules of
 * Http\Middleware\CacheAtEdge. Nothing leaves the machine and no container is
 * woken; it is the same judgement the edge makes, made here.
 *
 * Only GET routes without a required parameter are asked, and not those behind
 * an auth middleware: a page only a signed-in visitor sees is never kept at
 * the edge, and asking it says nothing.
 */
final class SleepCommand extends Command
{
    /**
     * The extensions the pull zone serves from the storage zone when the
     * address carries a version (`?v=`). The same list as the rules Brewless
     * puts on the zone; see the documentation under Versioned files from
     * `public/`.
     */
    private const array VERSIONABLE = ['css', 'js', 'mjs', 'svg', 'ico', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'woff2', 'woff', 'ttf', 'otf', 'webmanifest'];

    /** Routes of tooling that no visitor asks for. */
    private const array NOT_A_PAGE = ['_ops', '_ignition', '_debugbar', 'telescope', 'horizon', 'pulse', 'sanctum', 'livewire', 'broadcasting'];

    /** The shortest a schedule may repeat without keeping the worker awake all day. */
    private const int HOUR = 3600;

    protected $signature = 'brewless:sleep
        {--path=* : Only these addresses, such as / or /pricing}
        {--strict : Fail when a page cannot be kept at the edge}';

    protected $description = 'What keeps this application\'s container awake, page by page';

    public function handle(Kernel $kernel, Schedule $schedule): int
    {
        $this->components->info('What keeps this container awake');

        $seconds = (int) config('brewless.edge.cache', 0);
        $this->settings($seconds);

        $pages = $this->pages($kernel);
        $this->schedule($schedule);
        $this->files($pages['unversioned']);

        return $this->verdict($pages, $seconds);
    }

    /** The address the application answers on, as `APP_URL` says; a page asked anywhere else is another page. */
    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * The handful of settings that decide most of it, as the container really
     * has them.
     */
    private function settings(int $seconds): void
    {
        $stateless = config('brewless.edge.stateless_guests');

        $this->components->twoColumnDetail(
            'Edge keeps a page for',
            $seconds > 0 ? $seconds.' seconds' : '<fg=yellow>nothing (edge.cache is 0)</>',
        );
        $this->components->twoColumnDetail(
            'Guests without a session',
            $stateless === false ? '<fg=yellow>off (stateless_guests is false)</>' : ($stateless === true ? 'on' : 'on where the framework allows it'),
        );
        $this->components->twoColumnDetail('Sessions in', (string) config('session.driver'));
        $this->components->twoColumnDetail('Queue on', (string) config('queue.default'));
        $this->components->twoColumnDetail('Asked in', app()->environment().', on '.$this->base());

        // A page can answer differently here than it does live, and only the live answer decides whether the
        // edge keeps it. Seen on a site whose homepage kept a session on a developer machine and not in
        // production (coding.agency, 5 November 2026).
        if (! app()->isProduction()) {
            $this->line('  <fg=gray>This is not production. For the answer that counts, run it there:</> brewless command production "brewless:sleep"');
        }

        $this->newLine();
    }

    /**
     * Every page, asked as a first visitor asks it, with the edge's own
     * verdict on the answer.
     *
     * @return array{kept: int, woken: list<array{path: string, reason: string}>, unversioned: list<string>}
     */
    private function pages(Kernel $kernel): array
    {
        $kept = 0;
        $woken = [];
        $unversioned = [];
        $paths = $this->paths();

        if ($paths === []) {
            $this->components->warn('No page to ask: this application has no GET route without a required parameter.');

            return ['kept' => 0, 'woken' => [], 'unversioned' => []];
        }

        foreach ($paths as $path) {
            // Each page is judged on its own: an auth guard another page resolved would
            // otherwise make every page after it look personal to CacheAtEdge.
            if (app()->resolved('auth')) {
                app('auth')->forgetGuards();
            }

            // Likewise the cookies a page queued: the cookie session driver queues the session
            // under its id, and the jar outlives one request here, so every page after it seemed
            // to set a cookie (refero-ict, 11 November 2026: /sitemap.xml and /llms.txt after /contact).
            if (app()->resolved('cookie')) {
                app('cookie')->flushQueuedCookies();
            }

            // And the session itself: the store the last page filled would be this page's too.
            if (app()->resolved('session')) {
                app('session')->forgetDrivers();
                app()->forgetInstance('session.store');
            }

            // On the application's own address, not on localhost: the hostname and the scheme decide
            // what middleware does with a request, and a page asked on the wrong host answers
            // differently from the one a visitor gets (seen on production, 5 November 2026).
            $request = Request::create($this->base().$path, 'GET', server: ['HTTP_ACCEPT' => 'text/html']);

            try {
                $response = $kernel->handle($request);
            } catch (Throwable $exception) {
                $woken[] = ['path' => $path, 'reason' => 'it could not be asked here: '.$exception->getMessage()];

                continue;
            }

            $reason = $this->whyNotKept($request, $response);

            if ($reason === null) {
                $kept++;
            } else {
                $woken[] = ['path' => $path, 'reason' => $reason];
            }

            $unversioned = [...$unversioned, ...$this->unversioned($response)];
        }

        $this->components->twoColumnDetail('<fg=green>Kept at the edge</>', $kept.' of '.count($paths).' pages');

        // Grouped by the rule they failed: on a real site hundreds of pages share one reason,
        // and the reason is the thing to act on, not the list.
        $byReason = [];

        foreach ($woken as $page) {
            $byReason[$page['reason']][] = $page['path'];
        }

        foreach ($byReason as $reason => $failed) {
            $this->line('  <fg=yellow>'.count($failed).' '.(count($failed) === 1 ? 'page' : 'pages').'</> — '.$reason);
            $this->examples($failed);
        }

        $this->newLine();

        return ['kept' => $kept, 'woken' => $woken, 'unversioned' => array_values(array_unique($unversioned))];
    }

    /**
     * Why the edge cannot keep this answer, in the words of the rule it
     * failed; null when it can. The rules are CacheAtEdge's own, in its order.
     */
    private function whyNotKept(Request $request, Response $response): ?string
    {
        $seconds = (int) config('brewless.edge.cache', 0);

        if ($response->headers->get('Cache-Control') === 'max-age=0, public, s-maxage='.$seconds) {
            return null;
        }

        if (! in_array($response->getStatusCode(), [200, 301, 308, 404, 410], true)) {
            return 'the answer is a '.$response->getStatusCode().'; the edge keeps 200, 301, 308, 404 and 410';
        }

        $cookies = array_map(static fn (object $cookie): string => (string) $cookie->getName(), $response->headers->getCookies());

        if (in_array((string) config('session.cookie'), $cookies, true)) {
            return 'it keeps a session, so the answer is this visitor\'s. A page that shows a CSRF token, or puts '
                .'anything in the session, keeps one even when a guest has nothing in it';
        }

        if ($cookies !== []) {
            return 'it sets a cookie ('.implode(', ', $cookies).'), so the answer is this visitor\'s';
        }

        if ($request->hasSession() && $request->session()->isStarted() && $request->attributes->get(StartSessionWhenNeeded::SKIPPED) !== true) {
            return 'it starts a session; a page that puts nothing in the session need not';
        }

        $vary = array_filter($response->getVary(), static fn (string $header): bool => strtolower(trim($header)) !== 'accept-encoding');

        if ($vary !== []) {
            return 'it varies on '.implode(', ', $vary).'; the edge keeps one answer per address';
        }

        $own = $response->headers->get('Cache-Control');

        if (is_string($own) && $own !== '' && $own !== 'no-cache, private') {
            return 'it sets Cache-Control itself ("'.$own.'"), so Brewless leaves it alone';
        }

        return 'it looked at who is signed in, so the answer is not the same for everybody';
    }

    /**
     * The files a page names on its own host that carry no version, and so
     * come from the container instead of the storage zone.
     *
     * @return list<string>
     */
    private function unversioned(Response $response): array
    {
        $body = $response->getContent();

        if (! is_string($body) || ! str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return [];
        }

        preg_match_all('/\s(?:src|href|poster)\s*=\s*(["\'])([^"\']+)\1/i', $body, $matches);

        $files = [];

        foreach ($matches[2] as $reference) {
            $reference = html_entity_decode(trim($reference), ENT_QUOTES | ENT_HTML5);
            $path = (string) parse_url($reference, PHP_URL_PATH);
            $query = (string) parse_url($reference, PHP_URL_QUERY);
            $host = parse_url($reference, PHP_URL_HOST);

            $ours = $host === null || $host === parse_url((string) config('app.url'), PHP_URL_HOST);
            $versioned = str_starts_with($query, 'v=') || str_contains($query, '&v=');
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if ($ours && ! $versioned && ! str_starts_with($path, '/storage/') && ! str_starts_with($path, '/build/') && in_array($extension, self::VERSIONABLE, true)) {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Scheduled work that repeats within the hour. Every tick wakes the worker
     * container, and a worker that never sleeps costs what a server costs.
     */
    private function schedule(Schedule $schedule): void
    {
        $often = [];

        foreach ($schedule->events() as $event) {
            $gap = $this->secondsBetweenRuns($event);

            if ($gap !== null && $gap < self::HOUR) {
                $often[] = ['what' => $this->nameOf($event), 'gap' => $gap];
            }
        }

        if ($often === []) {
            $this->components->twoColumnDetail('<fg=green>Schedule</>', 'nothing repeats within the hour');
            $this->newLine();

            return;
        }

        foreach ($often as $one) {
            $every = $one['gap'] < 120 ? $one['gap'].' seconds' : (int) round($one['gap'] / 60).' minutes';
            $this->line('  <fg=yellow>Every '.$every.'</> — '.$one['what'].', and every tick wakes the worker');
        }

        $this->newLine();
    }

    /** How long a scheduled event leaves between two runs; null when its expression cannot be read. */
    private function secondsBetweenRuns(Event $event): ?int
    {
        try {
            $cron = new CronExpression($event->expression);
            $first = $cron->getNextRunDate('now');

            return $cron->getNextRunDate($first)->getTimestamp() - $first->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /** What a person calls this scheduled work: its description, or the command without the php binary in front of it. */
    private function nameOf(Event $event): string
    {
        $name = is_string($event->description) && $event->description !== '' ? $event->description : (string) $event->command;

        // Laravel writes a command as "'/usr/bin/php' 'artisan' inspire"; only the last part says anything.
        $name = (string) preg_replace("#^.*'artisan'\s+#", '', $name);

        return Str::limit(trim($name), 60) ?: $event->getSummaryForDisplay();
    }

    /**
     * @param  list<string>  $unversioned
     */
    private function files(array $unversioned): void
    {
        if ($unversioned === []) {
            $this->components->twoColumnDetail('<fg=green>Files</>', 'every file your pages name comes from the storage zone');
            $this->newLine();

            return;
        }

        $this->line('  <fg=yellow>'.count($unversioned).' files your pages name have no ?v=</> — the edge fetches those from your container, not from the storage zone');
        $this->examples($unversioned);
        $this->newLine();
    }

    /**
     * A few of them, and how many more there are. A list of 500 addresses
     * tells nobody anything the number did not.
     *
     * @param  list<string>  $all
     */
    private function examples(array $all): void
    {
        foreach (array_slice($all, 0, 5) as $one) {
            $this->line('    '.$one);
        }

        if (count($all) > 5) {
            $this->line('    and '.(count($all) - 5).' more');
        }
    }

    /**
     * The addresses to ask: those given, or every GET route a visitor can
     * reach without signing in and without filling in a parameter.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        $given = array_values(array_filter((array) $this->option('path')));

        if ($given !== []) {
            return array_map(static fn (string $path): string => '/'.ltrim($path, '/'), $given);
        }

        $paths = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($this->asksAVisitor($route)) {
                $paths[] = '/'.ltrim(preg_replace('#/\{[^}]+\?\}#', '', $route->uri()) ?? '', '/');
            }
        }

        return array_values(array_unique($paths));
    }

    private function asksAVisitor(RoutingRoute $route): bool
    {
        $uri = $route->uri();

        $behindSignIn = array_filter(
            $route->gatherMiddleware(),
            static fn (mixed $middleware): bool => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')),
        );

        return in_array('GET', $route->methods(), true)
            && preg_match('/\{[^?}]+\}/', $uri) !== 1
            && ! Str::startsWith($uri, self::NOT_A_PAGE)
            && $behindSignIn === [];
    }

    /**
     * @param  array{kept: int, woken: list<array{path: string, reason: string}>, unversioned: list<string>}  $pages
     */
    private function verdict(array $pages, int $seconds): int
    {
        $woken = count($pages['woken']);

        if ($seconds === 0) {
            $this->components->error('edge.cache is 0, so the edge keeps nothing and every visitor wakes the container. Set edge.cache in brewless.yml.');

            return self::FAILURE;
        }

        if ($woken === 0 && $pages['unversioned'] === []) {
            $this->components->info('Nothing here wakes the container that need not. '.$pages['kept'].' pages come from the edge.');

            return self::SUCCESS;
        }

        $this->components->info('Read https://docs.brewless.eu/running/edge-cache for what to do about each of these.');

        return $woken > 0 && $this->option('strict') === true ? self::FAILURE : self::SUCCESS;
    }
}
