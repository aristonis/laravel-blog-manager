<?php

declare(strict_types=1);

use Aristonis\BlogManager\BlogManager;
use Aristonis\BlogManager\Facades\BlogManager as BlogManagerFacade;

it('registers the container binding and merges package config', function () {
    expect(app('blog-manager'))->toBeInstanceOf(BlogManager::class)
        ->and(config('blog-manager.media.disk'))->not->toBeNull()
        ->and(config('blog-manager.authorization.driver'))->toBe('none')
        ->and(config('blog-manager.api.enabled'))->toBeFalse()
        // v0.1 ships no default file-kind MIME types; the host opts in (D16).
        ->and(config('blog-manager.media.allowed_mime.file'))->toBe([]);
});

it('resolves the BlogManager facade', function () {
    expect(BlogManagerFacade::version())->toBe(BlogManager::VERSION);
});
