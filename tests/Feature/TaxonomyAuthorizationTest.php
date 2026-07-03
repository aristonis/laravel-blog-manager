<?php

declare(strict_types=1);

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Exceptions\AuthorizationDeniedException;
use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\Tag;
use Aristonis\BlogManager\Services\TaxonomyService;
use Illuminate\Support\Facades\Gate;

function authzTaxo(): TaxonomyService
{
    return app(TaxonomyService::class);
}

// ---- ability key contract (FR-59, AC-42) --------------------------------

it('publishes blog.taxonomy.manage as a known ability key', function () {
    expect(Abilities::all())->toContain('blog.taxonomy.manage');
});

// ---- term lifecycle is guarded by blog.taxonomy.manage (FR-59, AC-41) ---

it('guards term lifecycle with blog.taxonomy.manage only when enforce_in_services is on', function () {
    config()->set('blog-manager.authorization.driver', 'gate'); // denies without a policy

    // default enforce=false -> the service does not check, so create proceeds
    $cat = authzTaxo()->createCategory('News');
    $tag = authzTaxo()->createTag('php');
    expect(Category::query()->count())->toBe(1)
        ->and(Tag::query()->count())->toBe(1);

    // enforce=true -> every mutating lifecycle op is denied at the service layer
    config()->set('blog-manager.authorization.enforce_in_services', true);

    expect(fn () => authzTaxo()->createCategory('Sports'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => authzTaxo()->createTag('go'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => authzTaxo()->renameCategory($cat, 'World'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => authzTaxo()->renameTag($tag, 'golang'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => authzTaxo()->deleteCategory($cat))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => authzTaxo()->deleteTag($tag))
        ->toThrow(AuthorizationDeniedException::class);

    // the guard short-circuits before the transaction: nothing was written or changed
    expect(Category::query()->count())->toBe(1) // only the pre-enforce "News"
        ->and(Tag::query()->count())->toBe(1)   // only the pre-enforce "php"
        ->and($cat->fresh()->name)->toBe('News')
        ->and($tag->fresh()->name)->toBe('php');
});

it('permits term lifecycle under enforce when blog.taxonomy.manage is granted', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    config()->set('blog-manager.authorization.enforce_in_services', true);
    Gate::define('blog.taxonomy.manage', fn ($user = null) => true); // pins the exact ability

    $cat = authzTaxo()->createCategory('News');
    $tag = authzTaxo()->createTag('php');
    authzTaxo()->renameCategory($cat, 'World');
    authzTaxo()->renameTag($tag, 'golang');
    authzTaxo()->deleteCategory($cat);
    authzTaxo()->deleteTag($tag);

    expect(Category::query()->count())->toBe(0)
        ->and(Tag::query()->count())->toBe(0);
});

it('uses a distinct ability from post.update: taxonomy.manage does not authorize attach', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    config()->set('blog-manager.authorization.enforce_in_services', true);
    Gate::define('blog.taxonomy.manage', fn ($user = null) => true); // lifecycle allowed
    // blog.post.update deliberately NOT defined -> attach must still be denied

    $cat = authzTaxo()->createCategory('News'); // allowed by taxonomy.manage
    $post = Post::create(['title' => 'Hi', 'slug' => 'hi']);

    expect(fn () => authzTaxo()->categorize($post, [$cat]))
        ->toThrow(AuthorizationDeniedException::class);
});

// ---- attach-by-new-name stays a post edit, not term management (O-4) -----

it('lets a post.update editor auto-create and attach a new tag by name without taxonomy.manage', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    config()->set('blog-manager.authorization.enforce_in_services', true);
    config()->set('blog-manager.taxonomy.tags.auto_create', true);
    Gate::define('blog.post.update', fn ($user = null) => true);
    // blog.taxonomy.manage deliberately NOT granted — tagging a post is a post edit (O-4),
    // and tags are free-form, so auto-create rides on post.update, not term management.

    $post = Post::create(['title' => 'Hi', 'slug' => 'hi']);

    authzTaxo()->tag($post, ['brand-new-topic']);

    expect($post->tags()->count())->toBe(1)
        ->and(Tag::query()->where('name', 'brand-new-topic')->exists())->toBeTrue();
});
