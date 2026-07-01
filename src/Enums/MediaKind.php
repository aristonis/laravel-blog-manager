<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Enums;

/**
 * The classification of a media item, derived from its MIME type. Constrains
 * which block type may reference the media (image ↔ image, and so on).
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case File = 'file';
}
