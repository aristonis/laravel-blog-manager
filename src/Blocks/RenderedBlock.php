<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks;

/**
 * Immutable, presentation-ready view of a single block: its opaque id, type,
 * position, the type-specific sanitized `payload`, and the raw stored `source`.
 * Exposing both lets a decoupled frontend render server HTML or re-theme from
 * the raw data (e.g. render the markdown itself).
 */
final class RenderedBlock
{
    /**
     * @param  array<string, mixed>  $payload  sanitized, presentation-ready output
     * @param  array<string, mixed>  $source  the raw stored block data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $position,
        public readonly array $payload,
        public readonly array $source = [],
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
            'source' => $this->source,
            'payload' => $this->payload,
        ];
    }
}
