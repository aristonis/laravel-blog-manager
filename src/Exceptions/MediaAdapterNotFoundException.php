<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** The configured media storage adapter/driver is not registered. */
final class MediaAdapterNotFoundException extends BlogManagerException
{
    public const NUMBER_CODE = 3002;

    public const TEXT_CODE = 'blog.media.adapter_not_found';

    protected int $httpStatus = 500;
}
