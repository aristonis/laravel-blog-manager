<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Post;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after a post's category set changes (after the transaction
 * commits). One delta event per operation (O-6): categorize / syncCategories /
 * uncategorize each emit a single event carrying the Category models attached
 * ($added) and detached ($removed) by that call — either list may be empty.
 */
final class PostCategorized implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<Category>  $added
     * @param  list<Category>  $removed
     */
    public function __construct(
        public readonly Post $post,
        public readonly array $added,
        public readonly array $removed,
    ) {}
}
