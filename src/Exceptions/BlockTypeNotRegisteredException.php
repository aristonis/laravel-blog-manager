<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A block of an unregistered type was requested. */
final class BlockTypeNotRegisteredException extends BlogManagerException
{
    public const NUMBER_CODE = 2001;

    public const TEXT_CODE = 'blog.block.type_not_registered';

    protected int $httpStatus = 422;
}
