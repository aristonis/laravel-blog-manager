<?php

declare(strict_types=1);

use Aristonis\BlogManager\BlogManager;
use Aristonis\BlogManager\Facades\BlogManager as BlogManagerFacade;
use Aristonis\BlogManager\Services\SeoService;
use Aristonis\BlogManager\Services\TaxonomyService;

it('registers the container binding and merges package config', function () {
    expect(app('blog-manager'))->toBeInstanceOf(BlogManager::class)
        ->and(config('blog-manager.media.disk'))->not->toBeNull()
        ->and(config('blog-manager.authorization.driver'))->toBe('none')
        // v0.1 ships no default file-kind MIME types; the host opts in (D16).
        ->and(config('blog-manager.media.allowed_mime.file'))->toBe([]);
});

it('resolves the BlogManager facade', function () {
    expect(BlogManagerFacade::version())->toBe(BlogManager::VERSION);
});

it('pins the released 1.0.0 version string', function () {
    // AC-79: the VERSION constant is the single source of truth for the release
    // string; version() reflects it. Pinned so a stray bump fails the suite.
    expect(BlogManager::VERSION)->toBe('1.0.0')
        ->and(BlogManagerFacade::version())->toBe('1.0.0');
});

it('exposes the taxonomy service accessor', function () {
    expect(app('blog-manager')->taxonomy())->toBeInstanceOf(TaxonomyService::class);
});

it('exposes the SEO service accessor', function () {
    expect(app('blog-manager')->seo())->toBeInstanceOf(SeoService::class);
});
