<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Post;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a post's blocks are reordered. */
final class BlocksReordered implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Post $post) {}
}
