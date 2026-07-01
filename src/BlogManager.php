<?php

declare(strict_types=1);

namespace Aristonis\BlogManager;

/**
 * Root entry point for the package, resolved from the container as
 * `blog-manager` and proxied by the {@see Facades\BlogManager} facade.
 *
 * In v0.1 this is the composition root; post/block/media operations are
 * delegated to the domain services (wired in later step-groups). It holds no
 * transactional logic itself — services own transactions.
 */
final class BlogManager
{
    /**
     * Package version — the single source of truth for the release string.
     */
    public const VERSION = '0.1.0';

    public function version(): string
    {
        return self::VERSION;
    }
}
