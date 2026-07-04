<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;

/**
 * M7 — read-only orphaned-media query. A media item is orphaned when no LIVE
 * content block references it via content_blocks.media_item_id. Revision-snapshot
 * JSON references do NOT count (the future reclamation seam). Query only — it
 * never deletes.
 */
function mkMedia(string $filename): MediaItem
{
    return MediaItem::forceCreate([
        'kind' => MediaKind::Image,
        'mime' => 'image/png',
        'size' => 10,
        'original_filename' => $filename,
        'adapter' => 'filesystem',
        'disk' => 'public',
        'path' => 'blog-media/'.$filename,
    ]);
}

it('returns only media not referenced by any live content block (M7)', function () {
    $referenced = mkMedia('used.png');
    $orphan = mkMedia('unused.png');

    $post = Post::create(['title' => 'p', 'slug' => 'p']);
    ContentBlock::forceCreate([
        'post_id' => $post->id, 'type' => 'image', 'position' => 0,
        'media_item_id' => $referenced->id,
    ]);

    $orphaned = app(MediaManager::class)->orphaned();

    expect($orphaned->pluck('id')->all())->toBe([$orphan->id]);
});

it('reports media referenced only by a revision snapshot, not a live block, as orphaned (M7)', function () {
    $media = mkMedia('snapshot-only.png');
    $post = Post::create(['title' => 'p', 'slug' => 'p']);

    // A revision snapshot's JSON references the media by public_id, but NO live
    // content block does — snapshot references are not live references, so the
    // item is still orphaned (reclaimable).
    PostRevision::forceCreate([
        'post_id' => $post->id,
        'snapshot' => [
            'post' => ['title' => 'p', 'slug' => 'p', 'author_id' => null, 'status' => 'draft', 'published_at' => null],
            'blocks' => [[
                'public_id' => 'blk', 'type' => 'image', 'position' => 0, 'data' => ['alt' => 'x'],
                'media' => ['public_id' => $media->public_id, 'original_filename' => 'snapshot-only.png'],
            ]],
        ],
        'label' => 'snap',
        'created_by' => null,
    ]);

    expect(app(MediaManager::class)->orphaned()->pluck('id')->all())->toBe([$media->id]);
});
