<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after a media item is stored. */
final class MediaStored implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly MediaItem $media) {}
}
