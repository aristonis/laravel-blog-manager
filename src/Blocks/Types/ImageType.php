<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks\Types;

use Aristonis\BlogManager\Enums\MediaKind;

/** An image block referencing an image media item. */
final class ImageType extends MediaBlockType
{
    public function key(): string
    {
        return 'image';
    }

    public function requiresMediaKind(): MediaKind
    {
        return MediaKind::Image;
    }
}
