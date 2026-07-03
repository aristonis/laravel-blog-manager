<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Category;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a category is renamed (after the transaction commits). */
final class CategoryUpdated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Category $category) {}
}
