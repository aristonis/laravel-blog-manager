<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A post was created or updated with invalid attributes. */
final class InvalidPostDataException extends BlogManagerException
{
    public const NUMBER_CODE = 1002;

    public const TEXT_CODE = 'blog.post.invalid_data';

    protected int $httpStatus = 422;
}
