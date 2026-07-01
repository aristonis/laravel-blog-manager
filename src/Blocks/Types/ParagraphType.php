<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks\Types;

use Aristonis\BlogManager\Contracts\BlockType;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\InvalidBlockDataException;
use Illuminate\Support\Str;

/** A text paragraph, stored as plain or markdown and rendered to sanitized HTML. */
final class ParagraphType implements BlockType
{
    private const FORMATS = ['plain', 'markdown'];

    public function key(): string
    {
        return 'paragraph';
    }

    public function validate(array $data): array
    {
        $content = $data['content'] ?? null;
        if (! is_string($content)) {
            throw new InvalidBlockDataException('A paragraph requires string content.', ['field' => 'content']);
        }

        $format = $data['format'] ?? 'plain';
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidBlockDataException('A paragraph format must be "plain" or "markdown".', ['field' => 'format']);
        }

        return ['format' => $format, 'content' => $content];
    }

    public function requiresMediaKind(): ?MediaKind
    {
        return null;
    }

    public function renderPayload(array $data, ?string $mediaUrl): array
    {
        $content = is_string($data['content'] ?? null) ? $data['content'] : '';
        $format = ($data['format'] ?? 'plain') === 'markdown' ? 'markdown' : 'plain';

        $html = $format === 'markdown'
            ? Str::markdown($content, ['html_input' => 'strip', 'allow_unsafe_links' => false])
            : '<p>'.nl2br(e($content)).'</p>';

        return ['format' => $format, 'html' => $html];
    }
}
