<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A media item cannot be deleted while it is still referenced by a block. */
final class MediaInUseException extends BlogManagerException
{
    public const NUMBER_CODE = 3003;

    public const TEXT_CODE = 'blog.media.in_use';

    protected int $httpStatus = 409;
}
