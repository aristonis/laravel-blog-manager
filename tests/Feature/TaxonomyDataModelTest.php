<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\Tag;
use Illuminate\Database\QueryException;

it('persists a category with a ULID public_id and a slug', function () {
    $category = Category::create(['name' => 'News', 'slug' => 'news']);

    $fresh = $category->fresh();

    expect($fresh->public_id)->toBeString()->toHaveLength(26)
        ->and($fresh->name)->toBe('News')
        ->and($fresh->slug)->toBe('news')
        ->and($category->getRouteKeyName())->toBe('public_id')
        ->and($category->getKeyName())->toBe('id');          // internal numeric PK stays hidden
});

it('persists a tag with a ULID public_id and a slug', function () {
    $tag = Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);

    $fresh = $tag->fresh();

    expect($fresh->public_id)->toBeString()->toHaveLength(26)
        ->and($fresh->name)->toBe('Laravel')
        ->and($fresh->slug)->toBe('laravel')
        ->and($tag->getRouteKeyName())->toBe('public_id');
});

it('keeps category and tag slugs in separate namespaces', function () {
    // A category and a tag may share a slug (distinct tables — §2.4).
    Category::create(['name' => 'News', 'slug' => 'news']);
    Tag::create(['name' => 'News', 'slug' => 'news']);

    expect(Category::where('slug', 'news')->count())->toBe(1)
        ->and(Tag::where('slug', 'news')->count())->toBe(1);
});

it('attaches categories to a post and reads them back both ways', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $category = Category::create(['name' => 'News', 'slug' => 'news']);

    $post->categories()->attach($category->id);

    expect($post->categories()->count())->toBe(1)
        ->and($post->fresh()->categories->pluck('slug')->all())->toBe(['news'])
        // reverse relation resolves the pivot from the term side
        ->and($category->fresh()->posts->pluck('slug')->all())->toBe(['hello']);
});

it('attaches tags to a post and reads them back both ways', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);

    $post->tags()->attach($tag->id);

    expect($post->tags()->count())->toBe(1)
        ->and($post->fresh()->tags->pluck('slug')->all())->toBe(['php'])
        ->and($tag->fresh()->posts->pluck('slug')->all())->toBe(['hello']);
});

it('reads a post\'s categories ordered by name', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $zebra = Category::create(['name' => 'Zebra', 'slug' => 'zebra']);
    $alpha = Category::create(['name' => 'Alpha', 'slug' => 'alpha']);

    $post->categories()->attach([$zebra->id, $alpha->id]);

    expect($post->fresh()->categories->pluck('name')->all())->toBe(['Alpha', 'Zebra']);
});

it('rejects a duplicate post/category pair at the database', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $category = Category::create(['name' => 'News', 'slug' => 'news']);

    $post->categories()->attach($category->id);

    // The DB unique(post_id, category_id) backs idempotent membership: a second
    // raw attach of the same pair is rejected (services use sync/attach set ops).
    expect(fn () => $post->categories()->attach($category->id))
        ->toThrow(QueryException::class);

    expect($post->categories()->count())->toBe(1);
});

it('rejects a duplicate post/tag pair at the database', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);

    $post->tags()->attach($tag->id);

    expect(fn () => $post->tags()->attach($tag->id))
        ->toThrow(QueryException::class);

    expect($post->tags()->count())->toBe(1);
});

it('respects a configurable categories table name', function () {
    config()->set('blog-manager.tables.categories', 'custom_categories');

    expect((new Category)->getTable())->toBe('custom_categories');
});

it('respects a configurable tags table name', function () {
    config()->set('blog-manager.tables.tags', 'custom_tags');

    expect((new Tag)->getTable())->toBe('custom_tags');
});
