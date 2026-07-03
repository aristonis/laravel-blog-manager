<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;

// H3 — structural/internal columns must NOT be mass-assignable from a host that
// fills a model directly from untrusted input. The owning service sets these via
// forceFill/forceCreate; a plain fill() must leave them untouched.

it('does not let MediaItem mass-assign storage-routing columns or the public id', function () {
    $media = (new MediaItem)->fill([
        'disk' => 'evil-disk',
        'path' => '../../.env',
        'adapter' => 'evil-adapter',
        'public_id' => 'FORGEDPUBLICID0000000000AA',
    ]);

    expect($media->disk)->toBeNull()
        ->and($media->path)->toBeNull()
        ->and($media->adapter)->toBeNull()
        ->and($media->public_id)->toBeNull();
});

it('does not let ContentBlock mass-assign post_id, type, media_item_id or the public id', function () {
    $block = (new ContentBlock)->fill([
        'post_id' => 999,
        'type' => 'forged',
        'media_item_id' => 999,
        'public_id' => 'FORGEDPUBLICID0000000000AA',
    ]);

    expect($block->post_id)->toBeNull()
        ->and($block->type)->toBeNull()
        ->and($block->media_item_id)->toBeNull()
        ->and($block->public_id)->toBeNull();
});

it('does not let PostRevision mass-assign post_id, snapshot or the public id', function () {
    $revision = (new PostRevision)->fill([
        'post_id' => 999,
        'snapshot' => ['post' => ['title' => 'forged']],
        'public_id' => 'FORGEDPUBLICID0000000000AA',
    ]);

    expect($revision->post_id)->toBeNull()
        ->and($revision->snapshot)->toBeNull()
        ->and($revision->public_id)->toBeNull();
});

it('does not let Post mass-assign the public id', function () {
    $post = (new Post)->fill([
        'title' => 'legit',
        'public_id' => 'FORGEDPUBLICID0000000000AA',
    ]);

    expect($post->public_id)->toBeNull()
        ->and($post->title)->toBe('legit'); // title stays legitimately fillable
});
