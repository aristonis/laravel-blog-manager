<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/**
 * Invalid per-post SEO metadata: an over-cap string field (no silent
 * truncation), a non-string value where a string override is expected, or an
 * unknown field key. Every string field is capped fail-loud (see SeoService).
 */
final class InvalidSeoDataException extends BlogManagerException
{
    public const NUMBER_CODE = 6001;

    public const TEXT_CODE = 'blog.seo.invalid_data';

    protected int $httpStatus = 422;
}
