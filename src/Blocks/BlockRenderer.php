<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks;

use Aristonis\BlogManager\Models\ContentBlock;

/**
 * Turns a stored ContentBlock into a presentation-ready RenderedBlock by
 * delegating the payload to the block's registered type. Media URLs are resolved
 * by the caller (the services) and passed in, keeping this decoupled from storage.
 */
final class BlockRenderer
{
    public function __construct(private readonly BlockTypeRegistry $registry) {}

    public function render(ContentBlock $block, ?string $mediaUrl = null): RenderedBlock
    {
        $type = $this->registry->get($block->type);

        /** @var array<string, mixed> $data */
        $data = $block->data ?? [];

        return new RenderedBlock(
            (string) $block->public_id,
            $block->type,
            (int) $block->position,
            $type->renderPayload($data, $mediaUrl),
            $data,
        );
    }
}
