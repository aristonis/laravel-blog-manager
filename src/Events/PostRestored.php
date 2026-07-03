<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a post is restored from a revision (after the transaction commits). */
final class PostRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Post $post,
        public readonly PostRevision $revision,
    ) {}
}
