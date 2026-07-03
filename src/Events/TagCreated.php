<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\Tag;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a tag is created (after the transaction commits). */
final class TagCreated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Tag $tag) {}
}
