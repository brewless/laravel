<?php

declare(strict_types=1);

namespace Brewless\Laravel;

use Brewless\Laravel\Databases\DatabaseClient;
use Brewless\Laravel\Http\Controllers\OpsController;
use Brewless\Laravel\Http\Middleware\EnsureOpsSecret;
use Brewless\Laravel\Http\Middleware\PreviewHost;
use Brewless\Laravel\Http\Middleware\TrustEdgeHost;
use Brewless\Laravel\Queue\SignedDatabaseConnector;
use Brewless\Laravel\Queue\SignedSqsConnector;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\QueueManager;
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

        foreach (['ops', 'edge', 'preview', 'queue', 'databases'] as $section) {
            config(['brewless.'.$section => array_replace($defaults[$section], (array) config('brewless.'.$section))]);
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/brewless.php' => config_path('brewless.php')], 'brewless-config');

        // Every job this application queues is signed with a key derived from the
        // application key (JobSignature::key()), so a queue trigger of the provider
        // can run it without a secret of its own. Every container has that key,
        // also the one that serves visitors and has no ops secret.
        $this->app->afterResolving('queue', function (QueueManager $queues): void {
            $queues->extend('sqs', static fn (): SignedSqsConnector => new SignedSqsConnector);
            $queues->extend('database', fn (): SignedDatabaseConnector => new SignedDatabaseConnector($this->app['db']));
        });

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
