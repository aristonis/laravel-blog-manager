<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks\Types;

use Aristonis\BlogManager\Contracts\BlockType;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\InvalidBlockDataException;

/**
 * Shared behaviour for media blocks (image/video/file). The block references a
 * media item by FK; only optional caption/alt live in the payload. Concrete
 * types declare their required media kind.
 */
abstract class MediaBlockType implements BlockType
{
    abstract public function key(): string;

    abstract public function requiresMediaKind(): MediaKind;

    public function validate(array $data): array
    {
        $out = [];

        foreach (['caption', 'alt'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                if (! is_string($data[$field])) {
                    throw new InvalidBlockDataException(ucfirst($field).' must be a string.', ['field' => $field]);
                }
                $out[$field] = $data[$field];
            }
        }

        return $out;
    }

    public function renderPayload(array $data, ?string $mediaUrl): array
    {
        $payload = ['url' => $mediaUrl];

        foreach (['caption', 'alt'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $payload[$field] = e($data[$field]);
            }
        }

        return $payload;
    }
}
