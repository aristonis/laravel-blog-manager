<?php

declare(strict_types=1);

namespace Aristonis\BlogManager;

use Illuminate\Support\ServiceProvider;

/**
 * The connection point between the package and Laravel.
 *
 * `register()` merges the package config and binds the container services.
 * `boot()` wires publishing and (in later step-groups) migrations and the
 * optional API routes. Domain services and the extension registries are bound
 * here as they are introduced per step-group.
 */
final class BlogManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blog-manager.php', 'blog-manager');

        $this->app->singleton('blog-manager', fn (): BlogManager => new BlogManager);
        $this->app->alias('blog-manager', BlogManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/blog-manager.php' => config_path('blog-manager.php'),
            ], 'blog-manager-config');

            // Migrations are published from SG-3 (once database/migrations exists).
        }

        // The optional JSON API is registered from SG-8, only when
        // config('blog-manager.api.enabled') is true.
    }
}
