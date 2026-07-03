<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/**
 * Invalid taxonomy term data: an empty term name, or a duplicate category name
 * (category names are a curated, unique set — tags may repeat).
 */
final class InvalidTaxonomyDataException extends BlogManagerException
{
    public const NUMBER_CODE = 5003;

    public const TEXT_CODE = 'blog.taxonomy.invalid_data';

    protected int $httpStatus = 422;
}
