<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/**
 * A tag could not be found. Context key is 'id' when resolving by public id or
 * slug (getTag), and 'name' when resolving by name on the attach path (resolveTag).
 */
final class TagNotFoundException extends BlogManagerException
{
    public const NUMBER_CODE = 5002;

    public const TEXT_CODE = 'blog.tag.not_found';

    protected int $httpStatus = 404;
}
