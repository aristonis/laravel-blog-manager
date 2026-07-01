<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Media;

use Aristonis\BlogManager\Enums\MediaKind;
use Illuminate\Support\Str;

/**
 * Maps a MIME type to a MediaKind using the configurable `media.kind_map`
 * (e.g. `image/*` => image), falling back to `file` for anything unmatched.
 */
final class MediaKindResolver
{
    public function resolve(string $mime): MediaKind
    {
        /** @var array<string, mixed> $map */
        $map = (array) config('blog-manager.media.kind_map', []);

        foreach ($map as $pattern => $kind) {
            if (Str::is((string) $pattern, $mime)) {
                $resolved = MediaKind::tryFrom(is_string($kind) ? $kind : '');
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return MediaKind::File;
    }
}
