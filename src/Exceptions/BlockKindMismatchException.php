<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A media block referenced a media item whose kind does not match the block type. */
final class BlockKindMismatchException extends BlogManagerException
{
    public const NUMBER_CODE = 2003;

    public const TEXT_CODE = 'blog.block.kind_mismatch';

    protected int $httpStatus = 422;
}
