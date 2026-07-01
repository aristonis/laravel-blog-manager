<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a media item and its binary are deleted. */
final class MediaDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly MediaItem $media) {}
}
