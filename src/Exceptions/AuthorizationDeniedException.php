<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** An authorizer denied a blog ability for the current user. */
final class AuthorizationDeniedException extends BlogManagerException
{
    public const NUMBER_CODE = 4001;

    public const TEXT_CODE = 'blog.authorization.denied';

    protected int $httpStatus = 403;
}
