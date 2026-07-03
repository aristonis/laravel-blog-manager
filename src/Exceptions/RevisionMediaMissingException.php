<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/**
 * A restore was blocked because the revision references media that no longer
 * exists. The {@see self::context()} carries a `missing` list — one entry per
 * gap (block position, type, original filename, old media public id) — so the
 * host can prompt the user to re-upload and retry with a media remap.
 */
final class RevisionMediaMissingException extends BlogManagerException
{
    public const NUMBER_CODE = 3005;

    public const TEXT_CODE = 'blog.revision.media_missing';

    protected int $httpStatus = 422;
}
