<?php

declare(strict_types=1);

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Events\PostCategorized;
use Aristonis\BlogManager\Events\PostTagged;
use Aristonis\BlogManager\Exceptions\AuthorizationDeniedException;
use Aristonis\BlogManager\Exceptions\CategoryNotFoundException;
use Aristonis\BlogManager\Exceptions\TagNotFoundException;
use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\Tag;
use Aristonis\BlogManager\Services\TaxonomyService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

function taxo(): TaxonomyService
{
    return app(TaxonomyService::class);
}

function taxoPost(string $title = 'Hello', string $slug = 'hello'): Post
{
    return Post::create(['title' => $title, 'slug' => $slug]);
}

/** @return list<int> */
function ids(iterable $models): array
{
    return collect($models)->pluck('id')->sort()->values()->all();
}

// ---- categorize / syncCategories ----------------------------------------

it('attaches categories idempotently, reporting the added set', function () {
    $post = taxoPost();
    $a = taxo()->createCategory('Alpha');
    $b = taxo()->createCategory('Beta');

    Event::fake([PostCategorized::class]);
    taxo()->categorize($post, [$a, $b]);
    taxo()->categorize($post, [$a]); // re-attach adds no duplicate pivot row

    expect($post->categories()->count())->toBe(2);

    Event::assertDispatched(
        PostCategorized::class,
        fn (PostCategorized $e) => ids($e->added) === ids([$a, $b]) && $e->removed === [],
    );
});

it('replaces the category set on syncCategories, reporting added + removed', function () {
    $post = taxoPost();
    $a = taxo()->createCategory('Alpha');
    $b = taxo()->createCategory('Beta');
    taxo()->categorize($post, [$a]);

    Event::fake([PostCategorized::class]);
    taxo()->syncCategories($post, [$b]);

    expect($post->categories()->pluck('name')->all())->toBe(['Beta']); // $a removed, $b kept

    Event::assertDispatched(
        PostCategorized::class,
        fn (PostCategorized $e) => ids($e->added) === [$b->id] && ids($e->removed) === [$a->id],
    );
});

it('throws CategoryNotFoundException when categorizing an unknown category id', function () {
    $post = taxoPost();

    expect(fn () => taxo()->categorize($post, [(string) Str::ulid()]))
        ->toThrow(CategoryNotFoundException::class);
});

it('detaches categories, reporting only the rows it actually removed', function () {
    $post = taxoPost();
    $a = taxo()->createCategory('Alpha');
    $b = taxo()->createCategory('Beta');
    $gamma = taxo()->createCategory('Gamma'); // never attached
    $post->categories()->attach([$a->id, $b->id]);

    Event::fake([PostCategorized::class]);
    taxo()->uncategorize($post, [$a, $b, $gamma]); // detaching unattached $gamma is a no-op

    expect($post->categories()->count())->toBe(0);

    Event::assertDispatched(
        PostCategorized::class,
        fn (PostCategorized $e) => ids($e->removed) === ids([$a, $b]) && $e->added === [],
    );
});

// ---- tag / syncTags / untag ---------------------------------------------

it('auto-creates tags by name and attaches them when auto_create is on', function () {
    $post = taxoPost();

    Event::fake([PostTagged::class]);
    taxo()->tag($post, ['php', 'laravel']);

    expect($post->tags()->pluck('name')->all())->toEqualCanonicalizing(['php', 'laravel'])
        ->and(Tag::query()->count())->toBe(2);

    Event::assertDispatched(
        PostTagged::class,
        fn (PostTagged $e) => collect($e->added)->pluck('name')->sort()->values()->all() === ['laravel', 'php'],
    );
});

it('reuses an existing tag by name instead of creating a duplicate', function () {
    $post = taxoPost();
    taxo()->createTag('php');

    taxo()->tag($post, ['php']);

    expect(Tag::query()->count())->toBe(1)
        ->and($post->tags()->count())->toBe(1);
});

it('resolves an existing tag by public id or model without creating a duplicate', function () {
    $post = taxoPost();
    $existing = taxo()->createTag('php');

    taxo()->tag($post, [$existing->public_id]); // ULID-shaped string -> resolve by public id
    taxo()->tag($post, [$existing]);            // model -> idempotent re-attach

    expect($post->tags()->count())->toBe(1)
        ->and(Tag::query()->count())->toBe(1);
});

it('throws TagNotFoundException for an unknown tag public id', function () {
    $post = taxoPost();

    expect(fn () => taxo()->tag($post, [(string) Str::ulid()]))
        ->toThrow(TagNotFoundException::class);
});

it('throws TagNotFoundException for an unknown tag name when auto_create is off', function () {
    config()->set('blog-manager.taxonomy.tags.auto_create', false);
    $post = taxoPost();

    expect(fn () => taxo()->tag($post, ['ghost']))
        ->toThrow(TagNotFoundException::class);

    expect(Tag::query()->count())->toBe(0);
});

it('replaces the tag set on syncTags', function () {
    $post = taxoPost();
    $a = taxo()->createTag('php');
    $b = taxo()->createTag('laravel');
    taxo()->tag($post, [$a]);

    taxo()->syncTags($post, [$b]);

    expect($post->tags()->pluck('name')->all())->toBe(['laravel']);
});

it('detaches tags, reporting the removed set', function () {
    $post = taxoPost();
    $php = taxo()->createTag('php');
    $post->tags()->attach($php->id);

    Event::fake([PostTagged::class]);
    taxo()->untag($post, [$php]);

    expect($post->tags()->count())->toBe(0);

    Event::assertDispatched(
        PostTagged::class,
        fn (PostTagged $e) => ids($e->removed) === [$php->id] && $e->added === [],
    );
});

// ---- rollback safety (AC-42) --------------------------------------------

it('rolls back the whole pivot change when syncCategories fails mid-transaction', function () {
    $post = taxoPost();
    $a = taxo()->createCategory('Alpha');
    $b = taxo()->createCategory('Beta');
    taxo()->categorize($post, [$a]); // $a attached before the fake

    Event::fake([PostCategorized::class]);

    // Fail after the pivot is rewritten but before the event fires: building the
    // delta payload retrieves Category models, so this throws mid-transaction.
    Category::retrieved(function (): void {
        throw new RuntimeException('forced mid-sync failure');
    });

    expect(fn () => taxo()->syncCategories($post, [$b]))
        ->toThrow(RuntimeException::class);

    // The detach of $a and attach of $b both roll back as one unit.
    expect($post->categories()->whereKey($a->id)->count())->toBe(1)
        ->and($post->categories()->whereKey($b->id)->count())->toBe(0);

    Event::assertNotDispatched(PostCategorized::class); // fires only after commit
});

// ---- authorization (AC-41) ----------------------------------------------

it('guards attach/detach with blog.post.update only when enforce_in_services is on', function () {
    config()->set('blog-manager.authorization.driver', 'gate'); // denies without a policy
    $post = taxoPost();
    $cat = taxo()->createCategory('News');

    // default enforce=false -> the service does not check, so the call proceeds
    taxo()->categorize($post, [$cat]);
    expect($post->categories()->count())->toBe(1);

    // enforce=true -> denied at the service layer
    config()->set('blog-manager.authorization.enforce_in_services', true);

    expect(fn () => taxo()->categorize($post, [$cat]))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => taxo()->tag($post, ['php']))
        ->toThrow(AuthorizationDeniedException::class);

    // the guard short-circuits before the transaction: no tag was auto-created
    expect(Tag::query()->count())->toBe(0);
});

it('permits attach under enforce_in_services when blog.post.update is granted', function () {
    // fixtures created before enforcement (term creation guards blog.taxonomy.manage)
    $post = taxoPost();
    $cat = taxo()->createCategory('News');

    config()->set('blog-manager.authorization.driver', 'gate');
    config()->set('blog-manager.authorization.enforce_in_services', true);
    Gate::define(Abilities::POST_UPDATE, fn ($user = null) => true); // pins the exact ability

    taxo()->categorize($post, [$cat]); // granted -> the guard lets it through

    expect($post->categories()->count())->toBe(1);
});
