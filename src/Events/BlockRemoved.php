<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\ContentBlock;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a block is removed and the remaining blocks re-sequenced. */
final class BlockRemoved implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly ContentBlock $block) {}
}
