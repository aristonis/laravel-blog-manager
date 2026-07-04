<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Media\Adapters\FilesystemAdapter;
use Aristonis\BlogManager\Media\MediaSource;
use Aristonis\BlogManager\Media\StoredMediaRef;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('stores a binary from a filesystem path (AC-59 adapter half)', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'bm-src-');
    file_put_contents($tmp, 'binary-bytes');

    try {
        $source = new MediaSource(
            path: $tmp,
            stream: null,
            mime: 'image/png',
            originalFilename: 'a.png',
            size: filesize($tmp) ?: 0,
        );

        $ref = (new FilesystemAdapter)->store($source, MediaKind::Image);

        expect($ref)->toBeInstanceOf(StoredMediaRef::class)
            ->and($ref->adapter)->toBe('filesystem')
            ->and($ref->disk)->toBe('public')
            ->and($ref->path)->toBeString();

        Storage::disk('public')->assertExists($ref->path);
        expect(Storage::disk('public')->get($ref->path))->toBe('binary-bytes');
    } finally {
        @unlink($tmp);
    }
});

it('stores a binary from an open stream (AC-60) and leaves the caller handle open (O-3, R-3)', function () {
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

        $ref = (new FilesystemAdapter)->store($source, MediaKind::Image);

        expect($ref)->toBeInstanceOf(StoredMediaRef::class)
            ->and($ref->adapter)->toBe('filesystem')
            ->and($ref->disk)->toBe('public')
            ->and($ref->path)->toBeString();

        Storage::disk('public')->assertExists($ref->path);
        expect(Storage::disk('public')->get($ref->path))->toBe('stream-bytes');

        // O-3: the adapter reads but never closes the caller-owned stream.
        expect(is_resource($stream))->toBeTrue();
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
});

it('never lets a caller-controlled filename extension leak into the stored stream path (web-shell vector)', function () {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'stream-bytes');
    rewind($stream);

    try {
        $source = new MediaSource(
            path: null,
            stream: $stream,
            mime: 'image/png',
            originalFilename: 'evil.php',
            size: 0,
        );

        $ref = (new FilesystemAdapter)->store($source, MediaKind::Image);

        // The dangerous caller extension must NOT survive into the stored path.
        expect($ref->path)->not->toEndWith('.php')
            ->and($ref->path)->not->toContain('.php');

        Storage::disk('public')->assertExists($ref->path);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
});
