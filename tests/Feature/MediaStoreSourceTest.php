<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Events\MediaStored;
use Aristonis\BlogManager\Exceptions\AuthorizationDeniedException;
use Aristonis\BlogManager\Exceptions\MediaValidationException;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Media\MediaSource;
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * Build a real filesystem-path MediaSource backed by a temp file carrying $bytes.
 * Returns [MediaSource, absolute path] — the caller unlinks the path when done.
 *
 * @return array{0: MediaSource, 1: string}
 */
function sg3PathSource(string $bytes, string $mime = 'image/png', ?int $size = null, string $name = 'a.png'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'bm-store-');
    file_put_contents($tmp, $bytes);

    $source = new MediaSource(
        path: $tmp,
        stream: null,
        mime: $mime,
        originalFilename: $name,
        size: $size ?? (strlen($bytes)),
    );

    return [$source, $tmp];
}

it('persists a MediaItem from a filesystem-path source, fires MediaStored, and lands the binary (AC-59)', function () {
    Event::fake([MediaStored::class]);

    [$source, $tmp] = sg3PathSource('image-bytes', 'image/png', name: 'a.png');

    try {
        $media = app(MediaManager::class)->storeSource($source);

        expect($media)->toBeInstanceOf(MediaItem::class)
            ->and($media->kind)->toBe(MediaKind::Image)
            ->and($media->mime)->toBe('image/png')
            ->and($media->original_filename)->toBe('a.png')
            ->and($media->adapter)->toBe('filesystem')
            ->and($media->public_id)->toHaveLength(26);

        // The binary landed via the adapter (no UploadedFile involved anywhere).
        Storage::disk('public')->assertExists($media->path);
        expect(Storage::disk('public')->get($media->path))->toBe('image-bytes');

        Event::assertDispatched(MediaStored::class, 1);
    } finally {
        @unlink($tmp);
    }
});

it('persists a MediaItem from an open-stream source and leaves the caller handle open (AC-60, O-3)', function () {
    Event::fake([MediaStored::class]);

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'stream-bytes');
    rewind($stream);

    try {
        $source = new MediaSource(
            path: null,
            stream: $stream,
            mime: 'image/png',
            originalFilename: 'b.png',
            size: 0,
        );

        $media = app(MediaManager::class)->storeSource($source);

        expect($media->kind)->toBe(MediaKind::Image)
            ->and($media->mime)->toBe('image/png')
            ->and($media->original_filename)->toBe('b.png');

        Storage::disk('public')->assertExists($media->path);
        expect(Storage::disk('public')->get($media->path))->toBe('stream-bytes');

        // O-3: the manager/adapter never closes the caller-owned stream.
        expect(is_resource($stream))->toBeTrue();

        Event::assertDispatched(MediaStored::class, 1);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
});

it('keeps store(UploadedFile) behavior-preserving — an equivalent MediaItem (AC-61)', function () {
    $file = UploadedFile::fake()->image('a.png');

    $media = app(MediaManager::class)->store($file);

    expect($media->kind)->toBe(MediaKind::Image)
        ->and($media->mime)->toBe('image/png')
        ->and($media->size)->toBe((int) ($file->getSize() ?: 0))
        ->and($media->original_filename)->toBe('a.png')
        ->and($media->adapter)->toBe('filesystem');

    Storage::disk('public')->assertExists($media->path);
});

it('rejects a disallowed-kind MediaSource with the same MediaValidationException (AC-62)', function () {
    [$source, $tmp] = sg3PathSource('text-bytes', 'text/plain', name: 'x.txt');

    try {
        expect(fn () => app(MediaManager::class)->storeSource($source))
            ->toThrow(MediaValidationException::class);

        expect(Storage::disk('public')->allFiles())->toBeEmpty();
    } finally {
        @unlink($tmp);
    }
});

it('rejects an oversized MediaSource with a known size with the same MediaValidationException (AC-62, R-4)', function () {
    config()->set('blog-manager.media.max_size.image', 10);

    // Known size (20) over the cap (10) -> the cap fires.
    [$source, $tmp] = sg3PathSource('small', 'image/png', size: 20, name: 'big.png');

    try {
        expect(fn () => app(MediaManager::class)->storeSource($source))
            ->toThrow(MediaValidationException::class);
    } finally {
        @unlink($tmp);
    }
});

it('accepts a size:0 source even with real over-cap bytes — cap skipped (O-4)', function () {
    config()->set('blog-manager.media.max_size.image', 10);

    // 12 real bytes (> cap 10) but declared size 0 (unknown) -> the cap is skipped.
    [$source, $tmp] = sg3PathSource('binary-bytes', 'image/png', size: 0, name: 'unknown.png');

    try {
        $media = app(MediaManager::class)->storeSource($source);

        expect($media)->toBeInstanceOf(MediaItem::class)
            ->and($media->size)->toBe(0);

        Storage::disk('public')->assertExists($media->path);
    } finally {
        @unlink($tmp);
    }
});

it('enforces MEDIA_UPLOAD identically on storeSource() and store() — guard not bypassable (AC-64)', function () {
    config()->set('blog-manager.authorization.driver', 'gate'); // denies without a policy
    config()->set('blog-manager.authorization.enforce_in_services', true);

    [$source, $tmp] = sg3PathSource('image-bytes', 'image/png', name: 'a.png');

    try {
        expect(fn () => app(MediaManager::class)->storeSource($source))
            ->toThrow(AuthorizationDeniedException::class);

        expect(fn () => app(MediaManager::class)->store(UploadedFile::fake()->image('a.png')))
            ->toThrow(AuthorizationDeniedException::class);

        // Nothing was stored through either denied entry point.
        expect(Storage::disk('public')->allFiles())->toBeEmpty();
    } finally {
        @unlink($tmp);
    }
});
