<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** The storage adapter failed to persist or remove a media binary. */
final class MediaStorageFailedException extends BlogManagerException
{
    public const NUMBER_CODE = 3004;

    public const TEXT_CODE = 'blog.media.storage_failed';

    protected int $httpStatus = 500;
}
