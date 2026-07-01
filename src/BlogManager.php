<?php

declare(strict_types=1);

namespace Aristonis\BlogManager;

use Aristonis\BlogManager\Blocks\BlockRenderer;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\BlockService;
use Aristonis\BlogManager\Services\PostService;

/**
 * Root entry point, resolved from the container as `blog-manager` and proxied by
 * the {@see Facades\BlogManager} facade. Composition root only — it delegates to
 * the domain services, which own transactions; it holds no transactional logic.
 */
final class BlogManager
{
    /**
     * Package version — the single source of truth for the release string.
     */
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly PostService $posts,
        private readonly BlockService $blocks,
        private readonly MediaManager $media,
        private readonly BlockRenderer $renderer,
    ) {}

    public function posts(): PostService
    {
        return $this->posts;
    }

    public function blocks(): BlockService
    {
        return $this->blocks;
    }

    public function media(): MediaManager
    {
        return $this->media;
    }

    /**
     * Render a post's blocks to an ordered list of sanitized payload arrays,
     * resolving media URLs through the active adapter.
     *
     * @return list<array<string, mixed>>
     */
    public function render(Post $post): array
    {
        return $post->blocks->map(function (ContentBlock $block): array {
            $url = $block->mediaItem !== null ? $this->media->url($block->mediaItem) : null;

            return $this->renderer->render($block, $url)->toArray();
        })->all();
    }

    public function version(): string
    {
        return self::VERSION;
    }
}
