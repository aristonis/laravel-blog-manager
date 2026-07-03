<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

/**
 * All retry attempts to append a block were exhausted because every attempt
 * tripped the unique(post_id, position) constraint, indicating a persistent
 * high-contention scenario.
 *
 * Callers may treat this as retriable at the application layer; the conflict
 * is structural (no data was written) and the post is in a consistent state.
 *
 * Number range: 2xxx blocks.  HTTP 409 Conflict communicates "retriable
 * resource conflict" without leaking internals.
 */
final class BlockPositionConflictException extends BlogManagerException
{
    public const NUMBER_CODE = 2005;

    public const TEXT_CODE = 'blog.block.position_conflict';

    protected int $httpStatus = 409;
}
