<?php

declare(strict_types=1);

use Aristonis\BlogManager\Events\CategoryCreated;
use Aristonis\BlogManager\Events\CategoryDeleted;
use Aristonis\BlogManager\Events\CategoryUpdated;
use Aristonis\BlogManager\Events\TagCreated;
use Aristonis\BlogManager\Events\TagDeleted;
use Aristonis\BlogManager\Events\TagUpdated;
use Aristonis\BlogManager\Exceptions\InvalidTaxonomyDataException;
use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\Tag;
use Aristonis\BlogManager\Services\TaxonomyService;
use Illuminate\Support\Facades\Event;

function taxonomy(): TaxonomyService
{
    return app(TaxonomyService::class);
}

// ---- create -------------------------------------------------------------

it('creates a category with a derived slug and fires CategoryCreated after commit', function () {
    Event::fake([CategoryCreated::class]);

    $category = taxonomy()->createCategory('News');

    expect($category->name)->toBe('News')
        ->and($category->slug)->toBe('news')
        ->and($category->public_id)->toHaveLength(26);

    Event::assertDispatched(CategoryCreated::class);
});

it('creates a tag with a derived slug and fires TagCreated after commit', function () {
    Event::fake([TagCreated::class]);

    $tag = taxonomy()->createTag('Laravel');

    expect($tag->name)->toBe('Laravel')
        ->and($tag->slug)->toBe('laravel');

    Event::assertDispatched(TagCreated::class);
});

it('keeps category and tag slugs in separate namespaces', function () {
    // A category `news` and a tag `news` may coexist (§2.4) — both created cleanly.
    $category = taxonomy()->createCategory('News');
    $tag = taxonomy()->createTag('News');

    expect($category->slug)->toBe('news')
        ->and($tag->slug)->toBe('news');
});

it('honors an explicit slug on create, uniquified within the table', function () {
    taxonomy()->createCategory('Alpha', 'custom');
    $second = taxonomy()->createCategory('Beta', 'custom');

    expect(Category::query()->where('slug', 'custom')->count())->toBe(1)
        ->and($second->slug)->toBe('custom-2');
});

// ---- name rules: categories unique, tags lenient ------------------------

it('rejects a duplicate category name', function () {
    taxonomy()->createCategory('News');

    expect(fn () => taxonomy()->createCategory('News'))
        ->toThrow(InvalidTaxonomyDataException::class);

    expect(Category::query()->count())->toBe(1);
});

it('allows a duplicate tag name, suffixing only the slug', function () {
    $first = taxonomy()->createTag('News');
    $second = taxonomy()->createTag('News');

    expect($first->slug)->toBe('news')
        ->and($second->slug)->toBe('news-2')
        ->and(Tag::query()->where('name', 'News')->count())->toBe(2);
});

it('rejects an empty or whitespace category name', function () {
    expect(fn () => taxonomy()->createCategory('   '))
        ->toThrow(InvalidTaxonomyDataException::class);
});

it('rejects an empty tag name', function () {
    expect(fn () => taxonomy()->createTag(''))
        ->toThrow(InvalidTaxonomyDataException::class);
});

// ---- rename -------------------------------------------------------------

it('renames a category, keeping the slug unless a new one is supplied', function () {
    Event::fake([CategoryUpdated::class]);
    $category = taxonomy()->createCategory('News', 'news');

    taxonomy()->renameCategory($category, 'Breaking News');

    expect($category->fresh()->name)->toBe('Breaking News')
        ->and($category->fresh()->slug)->toBe('news'); // stable addressing

    Event::assertDispatched(CategoryUpdated::class);
});

it('re-uniquifies the slug when a rename supplies one explicitly', function () {
    taxonomy()->createCategory('Taken', 'taken');
    $category = taxonomy()->createCategory('Movable', 'movable');

    taxonomy()->renameCategory($category, 'Movable', 'taken');

    // the explicitly supplied slug collides, so it suffixes
    expect($category->fresh()->slug)->toBe('taken-2');
});

it('rejects renaming a category to another category\'s existing name', function () {
    $a = taxonomy()->createCategory('Alpha');
    $b = taxonomy()->createCategory('Beta');

    expect(fn () => taxonomy()->renameCategory($b, 'Alpha'))
        ->toThrow(InvalidTaxonomyDataException::class);

    // renaming a category to its own current name is a no-op, not a conflict
    expect(fn () => taxonomy()->renameCategory($a, 'Alpha'))->not->toThrow(InvalidTaxonomyDataException::class);
});

it('renames a tag and fires TagUpdated, tags may share a name', function () {
    Event::fake([TagUpdated::class]);
    taxonomy()->createTag('Existing');
    $tag = taxonomy()->createTag('Other', 'other');

    // renaming a tag to an existing tag name is allowed (tags are lenient)
    taxonomy()->renameTag($tag, 'Existing');

    expect($tag->fresh()->name)->toBe('Existing')
        ->and($tag->fresh()->slug)->toBe('other'); // slug untouched without an explicit one

    // an explicit slug on rename is applied and re-uniquified
    taxonomy()->renameTag($tag, 'Existing', 'renamed-slug');
    expect($tag->fresh()->slug)->toBe('renamed-slug');

    Event::assertDispatched(TagUpdated::class);
});

// ---- delete: posts survive, pivots detach -------------------------------

it('deletes a category, detaching pivots while every post survives', function () {
    Event::fake([CategoryDeleted::class]);
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $category = taxonomy()->createCategory('News');
    $post->categories()->attach($category->id); // raw attach (categorize is SG-3)

    taxonomy()->deleteCategory($category);

    expect(Category::query()->count())->toBe(0)
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue() // post survives
        ->and($post->fresh()->categories()->count())->toBe(0);          // pivot gone

    Event::assertDispatched(CategoryDeleted::class);
});

it('deletes a tag, detaching pivots while every post survives', function () {
    Event::fake([TagDeleted::class]);
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $tag = taxonomy()->createTag('PHP');
    $post->tags()->attach($tag->id);

    taxonomy()->deleteTag($tag);

    expect(Tag::query()->count())->toBe(0)
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and($post->fresh()->tags()->count())->toBe(0);

    Event::assertDispatched(TagDeleted::class);
});
