<?php

declare(strict_types=1);

use Aristonis\BlogManager\Exceptions\MediaValidationException;
use Aristonis\BlogManager\Media\MediaSource;

it('constructs from a path only', function () {
    $source = new MediaSource(
        path: '/tmp/example.png',
        stream: null,
        mime: 'image/png',
        originalFilename: 'example.png',
        size: 123,
    );

    expect($source->path)->toBe('/tmp/example.png')
        ->and($source->stream())->toBeNull()
        ->and($source->mime)->toBe('image/png')
        ->and($source->originalFilename)->toBe('example.png')
        ->and($source->size)->toBe(123);
});

it('constructs from a stream only', function () {
    $stream = fopen('php://temp', 'r+');
    expect($stream)->toBeResource();

    try {
        $source = new MediaSource(
            path: null,
            stream: $stream,
            mime: 'image/png',
            originalFilename: 'example.png',
            size: 0,
        );

        expect($source->path)->toBeNull()
            ->and($source->stream())->toBe($stream)
            ->and(is_resource($source->stream()))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

it('exposes the stream via stream() and returns null for a path source', function () {
    $stream = fopen('php://temp', 'r+');

    try {
        $streamSource = new MediaSource(
            path: null,
            stream: $stream,
            mime: 'image/png',
            originalFilename: 'example.png',
            size: 0,
        );

        expect($streamSource->stream())->toBe($stream)
            ->and(is_resource($streamSource->stream()))->toBeTrue();
    } finally {
        fclose($stream);
    }

    $pathSource = new MediaSource(
        path: '/tmp/example.png',
        stream: null,
        mime: 'image/png',
        originalFilename: 'example.png',
        size: 1,
    );

    expect($pathSource->stream())->toBeNull();
});

it('strips control characters from the original filename (FR-85 consistency)', function () {
    $source = new MediaSource(
        path: '/tmp/example.png',
        stream: null,
        mime: 'image/png',
        originalFilename: "a\r\nb.txt",
        size: 1,
    );

    expect($source->originalFilename)->toBe('ab.txt');
});

it('throws on a negative size (0 stays valid as unknown, O-4)', function () {
    expect(fn () => new MediaSource(
        path: '/tmp/example.png',
        stream: null,
        mime: 'image/png',
        originalFilename: 'example.png',
        size: -1,
    ))->toThrow(MediaValidationException::class);
});

it('throws when both a path and a stream are provided', function () {
    $stream = fopen('php://temp', 'r+');

    try {
        expect(fn () => new MediaSource(
            path: '/tmp/example.png',
            stream: $stream,
            mime: 'image/png',
            originalFilename: 'example.png',
            size: 1,
        ))->toThrow(MediaValidationException::class);
    } finally {
        fclose($stream);
    }
});

it('throws when neither a path nor a stream is provided', function () {
    expect(fn () => new MediaSource(
        path: null,
        stream: null,
        mime: 'image/png',
        originalFilename: 'example.png',
        size: 1,
    ))->toThrow(MediaValidationException::class);
});

it('treats an empty-string path as absent and throws when no stream is given', function () {
    expect(fn () => new MediaSource(
        path: '',
        stream: null,
        mime: 'image/png',
        originalFilename: 'example.png',
        size: 1,
    ))->toThrow(MediaValidationException::class);
});

it('rejects a non-resource stream (a string)', function () {
    expect(fn () => new MediaSource(
        path: null,
        stream: 'not-a-resource',
        mime: 'image/png',
        originalFilename: 'example.png',
        size: 1,
    ))->toThrow(MediaValidationException::class);
});

it('rejects a non-resource stream (an array)', function () {
    expect(fn () => new MediaSource(
        path: null,
        stream: ['not', 'a', 'resource'],
        mime: 'image/png',
        originalFilename: 'example.png',
        size: 1,
    ))->toThrow(MediaValidationException::class);
});
