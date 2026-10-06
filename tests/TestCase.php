<?php

declare(strict_types=1);

namespace Brewless\Laravel\Tests;

use Brewless\Laravel\BrewlessServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    public const string OPS_SECRET = 'ops-secret-for-tests';

    public const string EDGE_SECRET = 'edge-secret-for-tests';

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BrewlessServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('brewless.ops.secret', self::OPS_SECRET);
        $app['config']->set('brewless.ops.heartbeat_store', 'array');
        $app['config']->set('brewless.edge.secret', self::EDGE_SECRET);
        $app['config']->set('brewless.edge.domains', ['shop.example']);
        $app['config']->set('brewless.release', 'registry.example/shop:abc123');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        Route::get('/host', fn (): string => request()->getHost().'|'.(request()->headers->has('X-Brewless-Edge') ? 'secret-visible' : 'secret-gone'));
    }

    /**
     * @return array<string, string>
     */
    protected function ops(): array
    {
        return ['X-Brewless-Ops' => self::OPS_SECRET];
    }
}
