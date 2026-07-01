<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks;

/**
 * Immutable, presentation-ready view of a single block: its opaque id, type,
 * position, and the type-specific sanitized payload. This is what a host renders.
 */
final class RenderedBlock
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $position,
        public readonly array $payload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'position' => $this->position,
            'payload' => $this->payload,
        ];
    }
}
