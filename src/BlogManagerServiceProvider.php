<?php

declare(strict_types=1);

namespace Aristonis\BlogManager;

use Aristonis\BlogManager\Authorization\AuthorizationManager;
use Aristonis\BlogManager\Blocks\BlockRenderer;
use Aristonis\BlogManager\Blocks\BlockTypeRegistry;
use Aristonis\BlogManager\Blocks\Types\FileType;
use Aristonis\BlogManager\Blocks\Types\HeadingType;
use Aristonis\BlogManager\Blocks\Types\ImageType;
use Aristonis\BlogManager\Blocks\Types\ParagraphType;
use Aristonis\BlogManager\Blocks\Types\VideoType;
use Aristonis\BlogManager\Contracts\Authorizer;
use Aristonis\BlogManager\Media\MediaAdapterManager;
use Aristonis\BlogManager\Media\MediaKindResolver;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Services\BlockService;
use Aristonis\BlogManager\Services\PostService;
use Aristonis\BlogManager\Services\RevisionService;
use Aristonis\BlogManager\Services\SeoService;
use Aristonis\BlogManager\Services\TaxonomyService;
use Illuminate\Support\ServiceProvider;

/**
 * The connection point between the package and Laravel.
 *
 * `register()` merges the package config and binds the container services.
 * `boot()` wires config + migration publishing. The package ships no HTTP
 * layer (core-only, D25); the host owns its own transport over the services.
 */
final class BlogManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blog-manager.php', 'blog-manager');

        $this->app->singleton(PostService::class);
        $this->app->singleton(BlockService::class);
        $this->app->singleton(RevisionService::class);
        $this->app->singleton(TaxonomyService::class);
        $this->app->singleton(SeoService::class);
        $this->app->singleton(BlogManager::class);
        $this->app->alias(BlogManager::class, 'blog-manager');

        $this->registerBlocks();
        $this->registerMedia();
        $this->registerAuthorization();
    }

    /**
     * Bind the authorization driver registry and the active authorizer. Bound as
     * a factory (not a singleton) so it re-reads the configured driver at call
     * time; host apps register custom drivers via AuthorizationManager::extend().
     */
    private function registerAuthorization(): void
    {
        $this->app->singleton(AuthorizationManager::class, fn ($app): AuthorizationManager => new AuthorizationManager($app));
        $this->app->bind(Authorizer::class, fn ($app): Authorizer => $app->make(AuthorizationManager::class)->authorizer());
    }

    /**
     * Bind the media driver registry (default filesystem adapter), the kind
     * resolver, and the media manager service. Host apps register additional
     * adapters via MediaAdapterManager::extend() — no core edit (OCP).
     */
    private function registerMedia(): void
    {
        $this->app->singleton(MediaAdapterManager::class, fn ($app): MediaAdapterManager => new MediaAdapterManager($app));
        $this->app->singleton(MediaKindResolver::class);
        $this->app->singleton(MediaManager::class);
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
    }
}
