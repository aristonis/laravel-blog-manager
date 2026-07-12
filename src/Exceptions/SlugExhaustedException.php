<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

use Aristonis\BlogManager\Support\SlugGenerator;

/**
 * The package could not mint a free slug. Fires from two places: the internal
 * random-suffix budget in {@see SlugGenerator}
 * (a pathological collision cluster) and the caller-side collision-retry budget
 * in retryOnCollision (a lost slug race exhausted its bounded retries). Both are
 * astronomically rare, server-side exhaustions the caller cannot fix by changing
 * input — hence a 500, not a client 409.
 */
final class SlugExhaustedException extends BlogManagerException
{
    public const NUMBER_CODE = 9002;

    public const TEXT_CODE = 'blog.slug.exhausted';

    protected int $httpStatus = 500;
}
