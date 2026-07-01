<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/** A reorder targeted a position outside the post's [0, n-1] range. */
final class BlockPositionOutOfRangeException extends BlogManagerException
{
    public const NUMBER_CODE = 2004;

    public const TEXT_CODE = 'blog.block.position_out_of_range';

    protected int $httpStatus = 422;
}
