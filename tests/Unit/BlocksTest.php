<?php

declare(strict_types=1);

use Aristonis\BlogManager\Blocks\BlockRenderer;
use Aristonis\BlogManager\Blocks\BlockTypeRegistry;
use Aristonis\BlogManager\Blocks\RenderedBlock;
use Aristonis\BlogManager\Blocks\Types\FileType;
use Aristonis\BlogManager\Blocks\Types\HeadingType;
use Aristonis\BlogManager\Blocks\Types\ImageType;
use Aristonis\BlogManager\Blocks\Types\ParagraphType;
use Aristonis\BlogManager\Blocks\Types\VideoType;
use Aristonis\BlogManager\Contracts\BlockType;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\BlockTypeNotRegisteredException;
use Aristonis\BlogManager\Exceptions\InvalidBlockDataException;
use Aristonis\BlogManager\Models\ContentBlock;

it('registers, resolves and lists block types, and rejects unknown ones (OCP)', function () {
    $registry = new BlockTypeRegistry;
    $fake = new class implements BlockType
    {
        public function key(): string
        {
            return 'fake';
        }

        public function validate(array $data): array
        {
            return $data;
        }

        public function requiresMediaKind(): ?MediaKind
        {
            return null;
        }

        public function renderPayload(array $data, ?string $mediaUrl): array
        {
            return ['ok' => true];
        }
    };

    $registry->register($fake);

    expect($registry->has('fake'))->toBeTrue()
        ->and($registry->get('fake'))->toBe($fake)
        ->and($registry->keys())->toBe(['fake']);

    expect(fn () => $registry->get('nope'))->toThrow(BlockTypeNotRegisteredException::class);
});

it('seeds the default block types', function () {
    expect(app(BlockTypeRegistry::class)->keys())
        ->toContain('heading', 'paragraph', 'image', 'video', 'file');
});

it('validates and escapes a heading', function () {
    $type = new HeadingType;

    expect($type->validate(['text' => 'Hi', 'level' => 3]))->toBe(['text' => 'Hi', 'level' => 3])
        ->and($type->requiresMediaKind())->toBeNull()
        ->and($type->renderPayload(['level' => 2, 'text' => 'A <b>x</b>'], null))
        ->toBe(['level' => 2, 'html' => '<h2>A &lt;b&gt;x&lt;/b&gt;</h2>']);

    expect(fn () => $type->validate(['text' => '']))->toThrow(InvalidBlockDataException::class);
    expect(fn () => $type->validate(['text' => 'ok', 'level' => 9]))->toThrow(InvalidBlockDataException::class);
});

it('clamps an out-of-range heading level on render (L1)', function () {
    $type = new HeadingType;

    // validate() enforces 1-6 on input, but a restored / hand-written snapshot
    // can carry an out-of-range level; render must clamp so it never emits a
    // bogus <h99>/<h0> tag.
    $high = $type->renderPayload(['level' => 99, 'text' => 'Hi'], null);
    expect($high['level'])->toBe(6)
        ->and($high['html'])->toBe('<h6>Hi</h6>');

    $low = $type->renderPayload(['level' => 0, 'text' => 'Hi'], null);
    expect($low['level'])->toBe(1)
        ->and($low['html'])->toBe('<h1>Hi</h1>');
});

it('renders markdown safely and plain text escaped', function () {
    $type = new ParagraphType;

    $markdown = $type->renderPayload(['format' => 'markdown', 'content' => 'Hi <script>alert(1)</script> **b**'], null)['html'];
    expect($markdown)->not->toContain('<script>')
        ->and($markdown)->toContain('<strong>b</strong>');

    expect($type->renderPayload(['format' => 'plain', 'content' => 'a <b> c'], null)['html'])
        ->toBe('<p>a &lt;b&gt; c</p>');

    expect(fn () => $type->validate(['content' => 123]))->toThrow(InvalidBlockDataException::class);
    expect(fn () => $type->validate(['content' => 'x', 'format' => 'html']))->toThrow(InvalidBlockDataException::class);
});

it('declares the required media kind and renders a media payload', function () {
    expect((new ImageType)->requiresMediaKind())->toBe(MediaKind::Image)
        ->and((new VideoType)->requiresMediaKind())->toBe(MediaKind::Video)
        ->and((new FileType)->requiresMediaKind())->toBe(MediaKind::File);

    $image = new ImageType;
    expect($image->renderPayload(['alt' => 'x <b>', 'caption' => 'cap'], 'https://cdn/x.png'))
        ->toBe(['url' => 'https://cdn/x.png', 'caption' => 'cap', 'alt' => 'x &lt;b&gt;']);

    expect(fn () => $image->validate(['alt' => 123]))->toThrow(InvalidBlockDataException::class);
});

it('escapes the media url in the render payload (L5)', function () {
    $image = new ImageType;

    // A custom adapter URL can embed user-influenced data; it must be e()-escaped
    // like caption/alt so a rendered payload can never carry an injection vector.
    $escaped = $image->renderPayload([], '"><script>alert(1)</script>');
    expect($escaped['url'])->toBe(e('"><script>alert(1)</script>'))
        ->and($escaped['url'])->not->toContain('<script>');

    // A benign hashed URL (no HTML-special chars) round-trips unchanged.
    expect($image->renderPayload([], 'https://cdn/x.png')['url'])->toBe('https://cdn/x.png');

    // Null media (no backing item) stays a real null, not the string "".
    expect($image->renderPayload([], null)['url'])->toBeNull();
});

it('renders a block via its type, wrapping block meta', function () {
    $block = (new ContentBlock)->forceFill(['type' => 'paragraph', 'position' => 3, 'data' => ['format' => 'plain', 'content' => 'hi']]);
    $block->public_id = '01XYZ';

    $rendered = app(BlockRenderer::class)->render($block);

    expect($rendered)->toBeInstanceOf(RenderedBlock::class)
        ->and($rendered->id)->toBe('01XYZ')
        ->and($rendered->type)->toBe('paragraph')
        ->and($rendered->position)->toBe(3)
        ->and($rendered->payload['html'])->toBe('<p>hi</p>');
});

it('exposes the raw source alongside the rendered payload', function () {
    $block = (new ContentBlock)->forceFill(['type' => 'paragraph', 'position' => 0, 'data' => ['format' => 'markdown', 'content' => '**hi**']]);
    $block->public_id = '01ABC';

    $rendered = app(BlockRenderer::class)->render($block);

    expect($rendered->source)->toBe(['format' => 'markdown', 'content' => '**hi**'])
        ->and($rendered->toArray()['source'])->toBe(['format' => 'markdown', 'content' => '**hi**'])
        ->and($rendered->toArray()['payload']['html'])->toContain('<strong>hi</strong>');
});
