<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks\Types;

use Aristonis\BlogManager\Contracts\BlockType;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\InvalidBlockDataException;

/** A heading: escaped text at a level 1-6. */
final class HeadingType implements BlockType
{
    private const MIN_LEVEL = 1;

    private const MAX_LEVEL = 6;

    private const DEFAULT_LEVEL = 2;

    public function key(): string
    {
        return 'heading';
    }

    public function validate(array $data): array
    {
        $text = $data['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidBlockDataException('A heading requires non-empty text.', ['field' => 'text']);
        }

        $level = $data['level'] ?? self::DEFAULT_LEVEL;
        if (! is_int($level) || $level < self::MIN_LEVEL || $level > self::MAX_LEVEL) {
            throw new InvalidBlockDataException('A heading level must be an integer 1-6.', ['field' => 'level']);
        }

        return ['text' => $text, 'level' => $level];
    }

    public function requiresMediaKind(): ?MediaKind
    {
        return null;
    }

    public function renderPayload(array $data, ?string $mediaUrl): array
    {
        $level = is_int($data['level'] ?? null) ? $data['level'] : self::DEFAULT_LEVEL;
        $text = is_string($data['text'] ?? null) ? $data['text'] : '';

        return [
            'level' => $level,
            'html' => '<h'.$level.'>'.e($text).'</h'.$level.'>',
        ];
    }
}
