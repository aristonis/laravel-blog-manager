<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks\Types;

use Aristonis\BlogManager\Enums\MediaKind;

/** A generic file block referencing a file media item. */
final class FileType extends MediaBlockType
{
    public function key(): string
    {
        return 'file';
    }

    public function requiresMediaKind(): MediaKind
    {
        return MediaKind::File;
    }
}
