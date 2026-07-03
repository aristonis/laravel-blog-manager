<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\PostRevision;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a post revision is captured (after the transaction commits). */
final class PostRevisionCreated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly PostRevision $revision) {}
}
