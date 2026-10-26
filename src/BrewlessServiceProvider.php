<?php

declare(strict_types=1);

namespace Brewless\Laravel;

use Brewless\Laravel\Database\MySqlConnection;
use Brewless\Laravel\Database\PostgresConnection;
use Brewless\Laravel\Databases\DatabaseClient;
use Brewless\Laravel\Http\Controllers\OpsController;
use Brewless\Laravel\Http\Middleware\EnsureOpsSecret;
use Brewless\Laravel\Http\Middleware\PreviewHost;
use Brewless\Laravel\Http\Middleware\TrustEdgeHost;
use Brewless\Laravel\Mail\MailCopies;
use Brewless\Laravel\Queue\SignedDatabaseConnector;
use Brewless\Laravel\Queue\SignedSqsConnector;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class BrewlessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/brewless.php', 'brewless');
        $this->fillInDefaults();

        // Before anything asks for the queue: the sqs connection gets the queue's own key.
        if (is_string(config('brewless.queue.key')) && config('brewless.queue.key') !== '') {
            config(['queue.connections.sqs' => array_filter((array) config('brewless.queue'), is_string(...)) + (array) config('queue.connections.sqs', [])]);
        }

        $this->trustRedisCertificate();
        $this->retryLostConnections();

        // Read when it is asked for, so a test or a command can set the settings first.
        $this->app->bind(DatabaseClient::class, static fn (Application $app): DatabaseClient => new DatabaseClient(
            $app->make(Factory::class),
            self::setting('brewless.databases.url'),
            self::setting('brewless.databases.environment'),
            self::setting('brewless.databases.key'),
            (int) config('brewless.databases.timeout', 15),
            (int) config('brewless.databases.connect_timeout', 5),
        ));
    }

    /**
     * The redis connections speak TLS to the cache of the environment and
     * trust only the certificate of its cluster. The host is an address on
     * the Private Network, which no certificate names, so the name is not
     * checked; the certificate itself is.
     */
    private function trustRedisCertificate(): void
    {
        $encoded = self::setting('brewless.redis.ca');
        $pem = $encoded === null ? false : base64_decode($encoded, true);

        if (! is_string($pem) || ! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return;
        }

        // Written once per certificate: a container keeps its files between requests while it runs.
        $file = sys_get_temp_dir().'/brewless-redis-'.substr(hash('sha256', $pem), 0, 16).'.pem';

        if (! is_file($file)) {
            file_put_contents($file, $pem, LOCK_EX);
        }

        $context = ['stream' => ['cafile' => $file, 'verify_peer' => true, 'verify_peer_name' => false]];
        $nodes = self::redisNodes();

        foreach ((array) config('brewless.redis.connections') as $connection) {
            if (! is_string($connection) || ! is_array($single = config('database.redis.'.$connection))) {
                continue;
            }

            if ($nodes === []) {
                config(['database.redis.'.$connection.'.scheme' => 'tls', 'database.redis.'.$connection.'.context' => $context]);

                continue;
            }

            // A cache in cluster mode: the connection becomes a cluster of every node, with the
            // connection's own user and password. Laravel takes a name from `clusters` once it is
            // gone from the single connections.
            config([
                'database.redis.clusters.'.$connection => [
                    ...array_map(static fn (array $node): array => ['host' => $node['host'], 'port' => $node['port'], 'scheme' => 'tls'], $nodes),
                    'options' => array_filter([
                        'username' => $single['username'] ?? null,
                        'password' => $single['password'] ?? null,
                        'context' => $context,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                ],
                'database.redis.options.cluster' => 'redis',
            ]);

            $connections = (array) config('database.redis');
            unset($connections[$connection]);
            config(['database.redis' => $connections]);
        }
    }

    /**
     * The nodes of a cache in cluster mode, from `BREWLESS_REDIS_NODES`.
     *
     * @return list<array{host: string, port: int}>
     */
    private static function redisNodes(): array
    {
        $nodes = [];

        foreach (explode(',', (string) config('brewless.redis.nodes', '')) as $node) {
            [$host, $port] = array_pad(explode(':', trim($node), 2), 2, '6379');

            if ($host !== '' && ctype_digit($port)) {
                $nodes[] = ['host' => $host, 'port' => (int) $port];
            }
        }

        return $nodes;
    }

    /**
     * PostgreSQL and MySQL connections try a query again for a while after
     * their connection went away (see RetriesLostConnections). An application
     * that made a connection class of its own for a driver keeps it.
     */
    private function retryLostConnections(): void
    {
        foreach (['pgsql' => PostgresConnection::class, 'mysql' => MySqlConnection::class] as $driver => $class) {
            if (Connection::getResolver($driver) === null) {
                Connection::resolverFor($driver, static fn (mixed $pdo, string $database, string $prefix, array $config): Connection => new $class($pdo, $database, $prefix, $config));
            }
        }
    }

    private static function setting(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * An application with a `config/brewless.php` of its own names only what
     * it changes: every setting it leaves out keeps the value from this package.
     */
    private function fillInDefaults(): void
    {
        $defaults = require __DIR__.'/../config/brewless.php';

        foreach (['ops', 'edge', 'preview', 'queue', 'redis', 'databases', 'database', 'mail'] as $section) {
            config(['brewless.'.$section => array_replace($defaults[$section], (array) config('brewless.'.$section))]);
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/brewless.php' => config_path('brewless.php')], 'brewless-config');

        // The agent skills of resources/boost/skills, for a project without Laravel Boost (which finds them
        // by itself): .agents/skills is read by Codex, Amp, OpenCode, Zed and others, .claude/skills by Claude Code.
        $skills = __DIR__.'/../resources/boost/skills';
        $this->publishes([$skills => base_path('.agents/skills')], 'brewless-skills');
        $this->publishes([$skills => base_path('.claude/skills')], 'brewless-skills-claude');

        // Every job this application queues is signed with a key derived from the
        // application key (JobSignature::key()), so a queue trigger of the provider
        // can run it without a secret of its own. Every container has that key,
        // also the one that serves visitors and has no ops secret.
        $this->app->afterResolving('queue', function (QueueManager $queues): void {
            $queues->extend('sqs', static fn (): SignedSqsConnector => new SignedSqsConnector);
            $queues->extend('database', fn (): SignedDatabaseConnector => new SignedDatabaseConnector($this->app['db']));
        });

        // A copy of every mail sent, when the environment keeps them.
        if (MailCopies::configured()) {
            Event::listen(MessageSent::class, [MailCopies::class, 'keep']);
        }

        // First of all middleware: the host decides a lot further down, so it is put back before anything reads it.
        // Prepended in reverse: the edge's hostname first, then the test address.
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(PreviewHost::class);
        $kernel->prependMiddleware(TrustEdgeHost::class);

        // Machine traffic from a release and from the provider's triggers: no
        // session, no CSRF, no domain. Without the secret they do not exist.
        Route::middleware(EnsureOpsSecret::class)->group(function (): void {
            Route::post('/_ops/migrate', [OpsController::class, 'release'])->name('brewless.ops.release');
            Route::post('/_ops/schedule', [OpsController::class, 'schedule'])->name('brewless.ops.schedule');
            Route::post('/_ops/queue', [OpsController::class, 'queue'])->name('brewless.ops.queue');
            Route::post('/_ops/queue/retry', [OpsController::class, 'retryFailed'])->name('brewless.ops.queue.retry');
            Route::post('/_ops/command', [OpsController::class, 'command'])->name('brewless.ops.command');
            Route::get('/_ops/status', [OpsController::class, 'status'])->name('brewless.ops.status');
            // A trigger of the provider can only post a body to the root of a container.
            Route::post('/', [OpsController::class, 'trigger'])->name('brewless.ops.trigger');
        });
    }
}
