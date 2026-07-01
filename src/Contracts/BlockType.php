<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Contracts;

use Aristonis\BlogManager\Enums\MediaKind;

/**
 * A registered kind of content block. One class per type; adding a type is a
 * registration in the BlockTypeRegistry, never an edit to a shared switch (OCP).
 */
interface BlockType
{
    /** The registry key, e.g. "paragraph". */
    public function key(): string;

    /**
     * Validate + normalize the block payload, or throw InvalidBlockDataException.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array;

    /**
     * The media kind this type must reference, or null for non-media types.
     * BlockService enforces the kind match generically from this value.
     */
    public function requiresMediaKind(): ?MediaKind;

    /**
     * Produce the sanitized, presentation-ready payload for this block.
     *
     * @param  array<string, mixed>  $data  the block's stored payload
     * @param  string|null  $mediaUrl  resolved URL for media types (null otherwise)
     * @return array<string, mixed>
     */
    public function renderPayload(array $data, ?string $mediaUrl): array;
}
