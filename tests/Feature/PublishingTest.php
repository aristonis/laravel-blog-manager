<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\PostStatus;
use Aristonis\BlogManager\Models\Post;
use Illuminate\Support\Carbon;

it('defaults a new post to draft with no published_at', function () {
    $post = Post::create(['title' => 'Draft me', 'slug' => 'draft-me'])->fresh();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->published_at)->toBeNull();
});

it('casts status to the PostStatus enum and published_at to a datetime', function () {
    $post = Post::create([
        'title' => 'P',
        'slug' => 'p',
        'status' => PostStatus::Published,
        'published_at' => '2026-01-01 00:00:00',
    ])->fresh();

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->toBeInstanceOf(Carbon::class)
        ->and($post->published_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('published scope returns only published posts whose published_at has passed', function () {
    Carbon::setTestNow('2026-07-02 12:00:00');

    $visible = Post::create([
        'title' => 'Visible', 'slug' => 'visible',
        'status' => PostStatus::Published, 'published_at' => now()->subHour(),
    ]);
    Post::create(['title' => 'Draft', 'slug' => 'draft', 'status' => PostStatus::Draft]);
    Post::create([
        'title' => 'Scheduled', 'slug' => 'scheduled',
        'status' => PostStatus::Published, 'published_at' => now()->addHour(),
    ]);

    expect(Post::published()->pluck('public_id')->all())->toBe([$visible->public_id]);

    Carbon::setTestNow();
});

it('draft scope returns only drafts', function () {
    $draft = Post::create(['title' => 'D', 'slug' => 'd', 'status' => PostStatus::Draft]);
    Post::create([
        'title' => 'Pub', 'slug' => 'pub',
        'status' => PostStatus::Published, 'published_at' => now()->subDay(),
    ]);

    expect(Post::draft()->pluck('public_id')->all())->toBe([$draft->public_id]);
});
