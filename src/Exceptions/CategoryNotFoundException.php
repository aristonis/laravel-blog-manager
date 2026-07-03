<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A category could not be found by its public id or slug. */
final class CategoryNotFoundException extends BlogManagerException
{
    public const NUMBER_CODE = 5001;

    public const TEXT_CODE = 'blog.category.not_found';

    protected int $httpStatus = 404;
}
