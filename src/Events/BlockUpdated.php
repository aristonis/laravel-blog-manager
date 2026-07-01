<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\ContentBlock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a block's payload is updated. */
final class BlockUpdated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly ContentBlock $block) {}
}
