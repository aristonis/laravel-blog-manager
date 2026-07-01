<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Resources;

use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaItem
 */
final class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MediaItem $media */
        $media = $this->resource;

        return [
            'id' => $media->public_id,
            'kind' => $media->kind->value,
            'mime' => $media->mime,
            'size' => $media->size,
            'filename' => $media->original_filename,
            'url' => app(MediaManager::class)->url($media),
        ];
    }
}
