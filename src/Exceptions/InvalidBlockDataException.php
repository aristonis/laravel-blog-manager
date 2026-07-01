<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A block payload failed its type's validation. */
final class InvalidBlockDataException extends BlogManagerException
{
    public const NUMBER_CODE = 2002;

    public const TEXT_CODE = 'blog.block.invalid_data';

    protected int $httpStatus = 422;
}
