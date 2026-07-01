<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Facades;

use Illuminate\Support\Facades\Facade;

/**
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
