<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

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

/**
 * SG-3 / FR-89 — dual-shape orphan query over one shared anti-join builder.
 *
 * AC-70: `orphaned()` contents are unchanged by the whereNotIn→whereNotExists
 * rewrite for a mixed fixture (regression guard).
 * AC-71: `orphanedLazy()` returns a cursor-backed LazyCollection that materialises
 * to the same set as `orphaned()`.
 * AC-72: a NULL-`media_item_id` block hides no orphan, and the compiled query
 * carries no `is not null` guard (the dropped dead-code check).
 */
it('returns exactly the unreferenced items for a mixed fixture after the whereNotExists rewrite (AC-70)', function () {
    // Two referenced + two orphaned — a broader mix than the single-orphan case
    // above, so the anti-join rewrite is pinned against a real referenced set.
    $refA = mkMedia('ref-a.png');
    $refB = mkMedia('ref-b.png');
    $orphanA = mkMedia('orphan-a.png');
    $orphanB = mkMedia('orphan-b.png');

    $post = Post::create(['title' => 'p', 'slug' => 'p']);
    ContentBlock::forceCreate([
        'post_id' => $post->id, 'type' => 'image', 'position' => 0,
        'media_item_id' => $refA->id,
    ]);
    ContentBlock::forceCreate([
        'post_id' => $post->id, 'type' => 'image', 'position' => 1,
        'media_item_id' => $refB->id,
    ]);

    $orphaned = app(MediaManager::class)->orphaned()->pluck('id')->all();

    sort($orphaned);
    $expected = [$orphanA->id, $orphanB->id];
    sort($expected);

    expect($orphaned)->toBe($expected);
});

it('orphanedLazy() streams via a cursor and materialises to the same set as orphaned() (AC-71)', function () {
    mkMedia('lazy-orphan.png');

    $referenced = mkMedia('lazy-ref.png');
    $post = Post::create(['title' => 'p', 'slug' => 'p']);
    ContentBlock::forceCreate([
        'post_id' => $post->id, 'type' => 'image', 'position' => 0,
        'media_item_id' => $referenced->id,
    ]);

    $manager = app(MediaManager::class);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $lazy = $manager->orphanedLazy();

    // Shape: it is a LazyCollection, not an eager Collection.
    expect($lazy)->toBeInstanceOf(LazyCollection::class);

    // Cursor signal: constructing the LazyCollection issues NO query — a cursor
    // defers execution until enumeration, unlike orphaned()'s eager ->get().
    expect(DB::connection()->getQueryLog())->toBeEmpty();

    // Enumerating the stream is what triggers the single underlying query.
    $ids = $lazy->pluck('id')->all();
    expect(DB::connection()->getQueryLog())->not->toBeEmpty();

    DB::connection()->disableQueryLog();

    // Materialised contents equal the eager shape.
    expect($ids)->toBe($manager->orphaned()->pluck('id')->all());
});

it('treats a NULL media_item_id block as no reference and compiles no is-not-null guard (AC-72)', function () {
    $orphan = mkMedia('null-orphan.png');
    $referenced = mkMedia('null-ref.png');

    $post = Post::create(['title' => 'p', 'slug' => 'p']);

    // A live reference to $referenced.
    ContentBlock::forceCreate([
        'post_id' => $post->id, 'type' => 'image', 'position' => 0,
        'media_item_id' => $referenced->id,
    ]);
    // A block that references NO media (media_item_id IS NULL). Under the correlated
    // whereColumn anti-join this row can never match any media row, so it must hide
    // no orphan — parity with the pre-rewrite whereNotNull guard.
    ContentBlock::forceCreate([
        'post_id' => $post->id, 'type' => 'text', 'position' => 1,
        'media_item_id' => null,
    ]);

    $manager = app(MediaManager::class);

    // The NULL row does not suppress the orphan on either shape.
    expect($manager->orphaned()->pluck('id')->all())->toBe([$orphan->id]);
    expect($manager->orphanedLazy()->pluck('id')->all())->toBe([$orphan->id]);

    // Dead-code check: the shared builder's compiled SQL carries no NULL guard.
    // whereNotNull('media_item_id') would compile to "is not null" — its absence
    // proves the guard was dropped, not silently carried into the whereNotExists.
    $method = new ReflectionMethod(MediaManager::class, 'orphanedQuery');
    $method->setAccessible(true);
    $sql = strtolower($method->invoke($manager)->toSql());

    expect($sql)->not->toContain('is not null');
    expect($sql)->toContain('not exists');
});
