<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** An uploaded media file failed MIME or size validation. */
final class MediaValidationException extends BlogManagerException
{
    public const NUMBER_CODE = 3001;

    public const TEXT_CODE = 'blog.media.validation_failed';

    protected int $httpStatus = 422;
}
