<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** Fallback for an unexpected package error that has no more specific type. */
final class GenericBlogManagerException extends BlogManagerException
{
    public const NUMBER_CODE = 9001;

    public const TEXT_CODE = 'blog.error';

    protected int $httpStatus = 500;
}
