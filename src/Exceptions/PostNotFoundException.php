<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A post could not be found by its public id or slug. */
final class PostNotFoundException extends BlogManagerException
{
    public const NUMBER_CODE = 1001;

    public const TEXT_CODE = 'blog.post.not_found';

    protected int $httpStatus = 404;
}
