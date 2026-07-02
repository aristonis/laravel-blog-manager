<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Post;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a post is returned to draft (after the transaction commits). */
final class PostUnpublished implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Post $post) {}
}
