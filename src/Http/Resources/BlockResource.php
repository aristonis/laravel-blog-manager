<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Resources;

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

        return [
            'id' => $block->public_id,
            'type' => $block->type,
            'position' => $block->position,
            // Named "attributes" (not "data") to avoid colliding with the
            // JsonResource "data" wrapper, which would suppress wrapping.
            'attributes' => $block->data,
            'media_id' => $block->mediaItem?->public_id,
        ];
    }
}
