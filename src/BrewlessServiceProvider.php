<?php

declare(strict_types=1);

namespace Brewless\Laravel;

use Brewless\Laravel\Http\Controllers\OpsController;
use Brewless\Laravel\Http\Middleware\EnsureOpsSecret;
use Brewless\Laravel\Http\Middleware\TrustEdgeHost;
use Illuminate\Contracts\Http\Kernel;
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
    }

    /**
     * An application with a `config/brewless.php` of its own names only what
     * it changes: every setting it leaves out keeps the value from this package.
     */
    private function fillInDefaults(): void
    {
        $defaults = require __DIR__.'/../config/brewless.php';

        foreach (['ops', 'edge', 'queue'] as $section) {
            config(['brewless.'.$section => array_replace($defaults[$section], (array) config('brewless.'.$section))]);
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/brewless.php' => config_path('brewless.php')], 'brewless-config');

        // First of all middleware: the host decides a lot further down, so it is put back before anything reads it.
        $this->app->make(Kernel::class)->prependMiddleware(TrustEdgeHost::class);

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
