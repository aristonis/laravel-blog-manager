<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Events;

use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after the media RECORD is deleted and its owning transaction has
 * committed. Binary cleanup is best-effort and runs in the same post-commit hook
 * immediately before this event, so a listener may observe an already-removed
 * binary — but a binary-removal failure is reported, not fatal, and never blocks
 * this event (the record deletion is authoritative).
 */
final class MediaDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly MediaItem $media) {}
}
