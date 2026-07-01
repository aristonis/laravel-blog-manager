<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks\Types;

use Aristonis\BlogManager\Enums\MediaKind;

/** A video block referencing a video media item. */
final class VideoType extends MediaBlockType
{
    public function key(): string
    {
        return 'video';
    }

    public function requiresMediaKind(): MediaKind
    {
        return MediaKind::Video;
    }
}
