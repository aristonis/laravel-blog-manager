<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A revision could not be found for the given post (absent or belongs to another post). */
final class RevisionNotFoundException extends BlogManagerException
{
    public const NUMBER_CODE = 1003;

    public const TEXT_CODE = 'blog.revision.not_found';

    protected int $httpStatus = 404;
}
