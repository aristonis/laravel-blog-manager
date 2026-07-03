<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Support\SlugGenerator;

function slugs(): SlugGenerator
{
    return app(SlugGenerator::class);
}

it('returns the base slug when the table is free', function () {
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello');
});

it('falls back when the base is empty', function () {
    expect(slugs()->unique(Post::class, '', fallback: 'post'))->toBe('post');
});

it('appends a deterministic suffix on collision, walking past taken suffixes', function () {
    Post::create(['title' => 'Hello', 'slug' => 'hello']);
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello-2');

    Post::create(['title' => 'Hello 2', 'slug' => 'hello-2']);
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello-3');
});

it('ignores a given row so a rename keeps its own slug', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    // Without the ignore the same slug collides and suffixes; ignoring the row
    // itself keeps the slug stable (the rename semantics PostService relies on).
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello-2')
        ->and(slugs()->unique(Post::class, 'hello', $post->id))->toBe('hello');
});
