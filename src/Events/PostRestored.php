<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a post is restored from a revision (after the transaction commits). */
final class PostRestored implements ShouldDispatchAfterCommit
{
    /**
     * @param  bool  $slugChanged  true when the snapshot's intended slug was already
     *                             taken by another post and restore had to append a
     *                             uniquifying suffix (the restored slug differs from
     *                             the one captured in the revision). Lets a host warn
     *                             the user / refresh deep-links instead of silently
     *                             diverging (M4).
     */
    public function __construct(
        public readonly Post $post,
        public readonly PostRevision $revision,
        public readonly bool $slugChanged = false,
    ) {}
}
