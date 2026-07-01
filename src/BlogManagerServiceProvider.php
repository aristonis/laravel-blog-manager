<?php

declare(strict_types=1);

namespace Aristonis\BlogManager;

use Aristonis\BlogManager\Blocks\BlockRenderer;
use Aristonis\BlogManager\Blocks\BlockTypeRegistry;
use Aristonis\BlogManager\Blocks\Types\FileType;
use Aristonis\BlogManager\Blocks\Types\HeadingType;
use Aristonis\BlogManager\Blocks\Types\ImageType;
use Aristonis\BlogManager\Blocks\Types\ParagraphType;
use Aristonis\BlogManager\Blocks\Types\VideoType;
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

        $this->registerBlocks();
    }

    /**
     * Bind the block-type registry (seeded with the default types) and renderer.
     * Host apps register additional types by resolving the registry and calling
     * register() from their own provider — no core edit (OCP).
     */
    private function registerBlocks(): void
    {
        $this->app->singleton(BlockTypeRegistry::class, function (): BlockTypeRegistry {
            $registry = new BlockTypeRegistry;

            foreach ([HeadingType::class, ParagraphType::class, ImageType::class, VideoType::class, FileType::class] as $type) {
                $registry->register(new $type);
            }

            return $registry;
        });

        $this->app->singleton(BlockRenderer::class, fn ($app): BlockRenderer => new BlockRenderer($app->make(BlockTypeRegistry::class)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/blog-manager.php' => config_path('blog-manager.php'),
            ], 'blog-manager-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'blog-manager-migrations');
        }

        // The optional JSON API is registered from SG-8, only when
        // config('blog-manager.api.enabled') is true.
    }
}
