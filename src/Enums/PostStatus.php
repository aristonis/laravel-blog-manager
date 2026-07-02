<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Enums;

/**
 * A post's lifecycle state. Visibility is computed, not stored: a post is
 * publicly visible only when it is Published AND its published_at has passed.
 * "Scheduled" is therefore just Published with a future published_at — no
 * separate state and no cron needed to flip it.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
