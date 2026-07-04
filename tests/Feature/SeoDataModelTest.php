<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostSeo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('persists and reads back all nine SEO fields for a post', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $post->seo()->create([
        'meta_title' => 'Meta Title',
        'meta_description' => 'Meta description text.',
        'canonical_url' => 'https://example.test/canonical',
        'noindex' => true,
        'nofollow' => true,
        'og_title' => 'OG Title',
        'og_description' => 'OG description text.',
        'og_image' => 'https://example.test/og.png',
        'og_type' => 'website',
    ]);

    $seo = $post->fresh()->seo;

    expect($seo)->not->toBeNull()
        ->and($seo->post_id)->toBe($post->id)
        ->and($seo->meta_title)->toBe('Meta Title')
        ->and($seo->meta_description)->toBe('Meta description text.')
        ->and($seo->canonical_url)->toBe('https://example.test/canonical')
        ->and($seo->noindex)->toBeTrue()
        ->and($seo->nofollow)->toBeTrue()
        ->and($seo->og_title)->toBe('OG Title')
        ->and($seo->og_description)->toBe('OG description text.')
        ->and($seo->og_image)->toBe('https://example.test/og.png')
        ->and($seo->og_type)->toBe('website');
});

it('defaults noindex/nofollow to false and og_type to null', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $post->seo()->create([]);

    $seo = $post->fresh()->seo;

    expect($seo->noindex)->toBeFalse()
        ->and($seo->nofollow)->toBeFalse()
        ->and($seo->og_type)->toBeNull();
});

it('casts noindex/nofollow to booleans', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $post->seo()->create(['noindex' => true]);

    $seo = $post->fresh()->seo;

    expect($seo->noindex)->toBeBool()->toBeTrue()
        ->and($seo->nofollow)->toBeBool()->toBeFalse();
});

it('rejects a second SEO row for the same post at the database (1:1)', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $post->seo()->create([]);

    // The DB unique(post_id) backs the 1:1 guarantee: a second raw row for the
    // same post is rejected (the service uses updateOrCreate — SG-2).
    expect(fn () => PostSeo::forceCreate(['post_id' => $post->id]))
        ->toThrow(QueryException::class);
});

it('cascades the SEO row deletion when its post is deleted', function () {
    // The SQLite test harness leaves PRAGMA foreign_keys OFF by default; enable it
    // so the migration's ON DELETE CASCADE (cascadeOnDelete) is actually enforced.
    DB::statement('PRAGMA foreign_keys = ON');

    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $post->seo()->create([]);
    $postId = $post->id;

    $post->delete();

    expect(PostSeo::query()->where('post_id', $postId)->exists())->toBeFalse();
});

it('exposes Post::seo() returning the record or null when unset', function () {
    $withSeo = Post::create(['title' => 'With', 'slug' => 'with']);
    $withSeo->seo()->create(['meta_title' => 'Set']);

    $withoutSeo = Post::create(['title' => 'Without', 'slug' => 'without']);

    expect($withSeo->fresh()->seo)->toBeInstanceOf(PostSeo::class)
        ->and($withSeo->fresh()->seo->meta_title)->toBe('Set')
        ->and($withoutSeo->fresh()->seo)->toBeNull();
});

it('returns the lowest-position paragraph via Post::firstParagraph()', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    // Structural fields (post_id/type) are set via forceCreate (H3). A non-paragraph
    // block at the lowest position and a later paragraph must both be ignored.
    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'image', 'position' => 0, 'data' => ['alt' => 'cover']]);
    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'paragraph', 'position' => 1, 'data' => ['content' => 'first para']]);
    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'paragraph', 'position' => 2, 'data' => ['content' => 'second para']]);

    $block = $post->firstParagraph;

    expect($block)->toBeInstanceOf(ContentBlock::class)
        ->and($block->type)->toBe('paragraph')
        ->and($block->position)->toBe(1)
        ->and($block->data)->toBe(['content' => 'first para']);
});

it('returns null from Post::firstParagraph() when the post has no paragraph block', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'image', 'position' => 0, 'data' => ['alt' => 'cover']]);

    expect($post->firstParagraph)->toBeNull();
});

it('respects a configurable post_seo table name', function () {
    config()->set('blog-manager.tables.post_seo', 'custom_post_seo');

    expect((new PostSeo)->getTable())->toBe('custom_post_seo');
});
