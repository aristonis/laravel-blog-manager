<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Resources;

use Aristonis\BlogManager\Blocks\BlockRenderer;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\ContentBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentBlock
 */
final class BlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ContentBlock $block */
        $block = $this->resource;

        $mediaUrl = $block->mediaItem !== null
            ? app(MediaManager::class)->url($block->mediaItem)
            : null;
        $rendered = app(BlockRenderer::class)->render($block, $mediaUrl);

        // Both the raw stored data (source) and the sanitized output (payload),
        // so a decoupled client can re-theme or consume server HTML. Neither key
        // is "data", so the JsonResource "data" wrapper is preserved.
        return [
            'id' => $block->public_id,
            'type' => $block->type,
            'position' => $block->position,
            'source' => $block->data ?? [],
            'payload' => $rendered->payload,
            'media_id' => $block->mediaItem?->public_id,
        ];
    }
}
