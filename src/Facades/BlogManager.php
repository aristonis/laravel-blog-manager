<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Aristonis\BlogManager\Services\PostService posts()
 * @method static \Aristonis\BlogManager\Services\BlockService blocks()
 * @method static \Aristonis\BlogManager\Media\MediaManager media()
 * @method static \Aristonis\BlogManager\Services\RevisionService revisions()
 * @method static \Aristonis\BlogManager\Services\TaxonomyService taxonomy()
 * @method static \Aristonis\BlogManager\Services\SeoService seo()
 * @method static list<array<string, mixed>> render(\Aristonis\BlogManager\Models\Post $post)
 * @method static string version()
 *
 * @see \Aristonis\BlogManager\BlogManager
 */
final class BlogManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'blog-manager';
    }
}
