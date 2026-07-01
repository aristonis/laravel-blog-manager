<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** The configured authorization driver is not registered. */
final class AuthorizationDriverNotFoundException extends BlogManagerException
{
    public const NUMBER_CODE = 4002;

    public const TEXT_CODE = 'blog.authorization.driver_not_found';

    protected int $httpStatus = 500;
}
