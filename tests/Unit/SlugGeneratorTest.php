<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Support\SlugGenerator;
use Illuminate\Support\Str;

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

it('caps the sequential suffix walk and falls back to a random suffix so it always terminates', function () {
    // Seed the base plus hello-2..hello-100 so the deterministic -2/-3/… walk
    // exhausts the cap. (100 mirrors SlugGenerator::MAX_SEQUENTIAL_SUFFIX.)
    $rows = [['title' => 'Hello', 'slug' => 'hello', 'public_id' => (string) Str::ulid()]];
    for ($n = 2; $n <= 100; $n++) {
        $rows[] = ['title' => "Hello {$n}", 'slug' => "hello-{$n}", 'public_id' => (string) Str::ulid()];
    }
    Post::query()->insert($rows);

    $slug = slugs()->unique(Post::class, 'hello');

    // Past the cap it must NOT keep walking numerically (that would be 'hello-101');
    // it falls back to a random/ULID suffix that is unique and free.
    expect($slug)->not->toMatch('/^hello-\d+$/')
        ->and($slug)->toStartWith('hello-')
        ->and(Post::query()->where('slug', $slug)->exists())->toBeFalse();
});
