<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\Tag;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after a post's tag set changes (after the transaction commits).
 * One delta event per operation (O-6): tag / syncTags / untag each emit a
 * single event carrying the Tag models attached ($added) and detached
 * ($removed) by that call — either list may be empty.
 */
final class PostTagged implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<Tag>  $added
     * @param  list<Tag>  $removed
     */
    public function __construct(
        public readonly Post $post,
        public readonly array $added,
        public readonly array $removed,
    ) {}
}
