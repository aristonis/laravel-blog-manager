<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Tests;

use Aristonis\BlogManager\BlogManagerServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Register the package's service provider for every test.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BlogManagerServiceProvider::class,
        ];
    }

    /**
     * Register the `testing` connection the suite runs against.
     *
     * Default (env unset, or DB_CONNECTION=sqlite) preserves the exact historical
     * behaviour: an in-memory SQLite connection. That keeps local runs and the full
     * PHP/Laravel/stability matrix byte-unchanged.
     *
     * When DB_CONNECTION is `mysql` or `pgsql` (set only by the CI `databases` job),
     * the `testing` connection is built for that driver from the standard DB_* env
     * vars pointing at the service container. This is a test-harness-only switch — no
     * runtime dependency and no change to `composer.json` (NFR-36).
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->resolveConnectionConfig());
    }

    /**
     * Build the `testing` connection config for the env-selected driver.
     *
     * @return array<string, mixed>
     */
    private function resolveConnectionConfig(): array
    {
        $driver = (string) env('DB_CONNECTION', 'sqlite');

        return match ($driver) {
            'mysql', 'pgsql' => [
                'driver' => $driver,
                'host' => (string) env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
                'database' => (string) env('DB_DATABASE', 'blog_manager_test'),
                'username' => (string) env('DB_USERNAME', 'blog_manager'),
                'password' => (string) env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };
    }

    /**
     * Run the package's own migrations against the in-memory database.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
