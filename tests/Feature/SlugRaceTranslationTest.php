<?php

declare(strict_types=1);

use Aristonis\BlogManager\Events\PostRestored;
use Aristonis\BlogManager\Exceptions\SlugExhaustedException;
use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;
use Aristonis\BlogManager\Models\Tag;
use Aristonis\BlogManager\Services\PostService;
use Aristonis\BlogManager\Services\RevisionService;
use Aristonis\BlogManager\Services\TaxonomyService;
use Aristonis\BlogManager\Support\SlugGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// Flush the model event listeners each test registers so a `creating`/`updating`
// squatter cannot bleed into an unrelated test in the same dispatcher scope.
afterEach(function (): void {
    Post::flushEventListeners();
    Tag::flushEventListeners();
    Category::flushEventListeners();
});

// ── helpers ──────────────────────────────────────────────────────────────────
//
// FORCED-COLLISION IDIOM (mirrors BlockConcurrencyTest)
// SQLite serialises, so real concurrency is impossible; instead a model event
// inserts a "squatter" row holding the exact slug the service just derived —
// inside the SAME transaction, via DB::table so no model event re-fires. The
// enclosing model write then trips unique(slug), raising a
// UniqueConstraintViolationException; the transaction rolls back (squatter
// vanishes). A single-shot squatter ($injected guard) lets the bounded retry
// re-derive the now-free slug and succeed; a persistent squatter exhausts it.

function slugRacePostsTable(): string
{
    $table = config('blog-manager.tables.posts', 'blog_posts');

    return is_string($table) ? $table : 'blog_posts';
}

function slugRaceTagsTable(): string
{
    $table = config('blog-manager.tables.tags', 'blog_tags');

    return is_string($table) ? $table : 'blog_tags';
}

function slugRaceCategoriesTable(): string
{
    $table = config('blog-manager.tables.categories', 'blog_categories');

    return is_string($table) ? $table : 'blog_categories';
}

/** Seat a squatter post at $slug inside the current transaction. */
function slugRaceSquatPost(string $slug): void
{
    DB::table(slugRacePostsTable())->insert([
        'public_id' => (string) Str::ulid(),
        'title' => 'Squatter',
        'slug' => $slug,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Seat a squatter tag at $slug inside the current transaction. */
function slugRaceSquatTag(string $slug): void
{
    DB::table(slugRaceTagsTable())->insert([
        'public_id' => (string) Str::ulid(),
        'name' => 'Squatter',
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Seat a squatter category at $slug inside the current transaction. */
function slugRaceSquatCategory(string $slug): void
{
    DB::table(slugRaceCategoriesTable())->insert([
        'public_id' => (string) Str::ulid(),
        'name' => 'Squatter '.$slug,
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── AC-67 — one bounded-retry-success case per FR-87 slug site ────────────────

it('retries and succeeds when a single slug collision fires on post create (AC-67)', function (): void {
    $injected = false;
    Post::creating(function (Post $post) use (&$injected): void {
        if ($injected) {
            return;
        }
        $injected = true;
        slugRaceSquatPost((string) $post->slug); // squat the slug create() just derived
    });

    $post = app(PostService::class)->create(['title' => 'Raced', 'slug' => 'raced']);

    expect($post->slug)->toBe('raced')
        ->and(Post::query()->where('slug', 'raced')->count())->toBe(1);
});

it('retries and succeeds when a single slug collision fires on post update (AC-67)', function (): void {
    $service = app(PostService::class);
    $post = $service->create(['title' => 'Orig', 'slug' => 'orig']);

    $injected = false;
    Post::updating(function (Post $p) use (&$injected): void {
        if ($injected) {
            return;
        }
        $injected = true;
        slugRaceSquatPost((string) $p->slug);
    });

    $updated = $service->update($post, ['slug' => 'renamed']);

    expect($updated->slug)->toBe('renamed')
        ->and(Post::query()->where('slug', 'renamed')->count())->toBe(1);
});

it('retries and succeeds when a single slug collision fires on tag create (AC-67)', function (): void {
    $injected = false;
    Tag::creating(function (Tag $tag) use (&$injected): void {
        if ($injected) {
            return;
        }
        $injected = true;
        slugRaceSquatTag((string) $tag->slug);
    });

    $tag = app(TaxonomyService::class)->createTag('Raced Tag', 'raced-tag');

    expect($tag->slug)->toBe('raced-tag')
        ->and(Tag::query()->where('slug', 'raced-tag')->count())->toBe(1);
});

it('retries and succeeds when a single slug collision fires on category rename (AC-67, FIX-C)', function (): void {
    $service = app(TaxonomyService::class);
    $category = $service->createCategory('Orig Category', 'orig-category');

    $injected = false;
    Category::updating(function (Category $c) use (&$injected): void {
        if ($injected) {
            return;
        }
        $injected = true;
        slugRaceSquatCategory((string) $c->slug); // squat the slug rename just derived
    });

    // The rename supplies a new slug, so renameCategory re-derives it inside the
    // tx; the single-shot squatter trips unique(slug) once, and the bounded retry
    // must re-derive the now-free slug and succeed — never leaking a raw
    // QueryException.
    $renamed = $service->renameCategory($category, 'Renamed Category', 'renamed-category');

    expect($renamed->slug)->toBe('renamed-category')
        ->and(Category::query()->where('slug', 'renamed-category')->count())->toBe(1);
});

it('retries and succeeds when a single slug collision fires on tag rename (AC-67, FIX-C)', function (): void {
    $service = app(TaxonomyService::class);
    $tag = $service->createTag('Orig Tag', 'orig-tag');

    $injected = false;
    Tag::updating(function (Tag $t) use (&$injected): void {
        if ($injected) {
            return;
        }
        $injected = true;
        slugRaceSquatTag((string) $t->slug); // squat the slug rename just derived
    });

    $renamed = $service->renameTag($tag, 'Renamed Tag', 'renamed-tag');

    expect($renamed->slug)->toBe('renamed-tag')
        ->and(Tag::query()->where('slug', 'renamed-tag')->count())->toBe(1);
});

it('retries restore on a single slug collision, firing exactly one PostRestored and one before-restore revision (AC-67, R-1)', function (): void {
    Event::fake([PostRestored::class]);
    $posts = app(PostService::class);
    $revs = app(RevisionService::class);

    $post = $posts->create(['title' => 'Orig', 'slug' => 'orig']);
    $revision = $revs->snapshot($post, 'snap');
    $posts->update($post, ['slug' => 'changed']); // so restore re-applies 'orig'

    $injected = false;
    Post::updating(function (Post $p) use (&$injected): void {
        if ($injected) {
            return;
        }
        $injected = true;
        slugRaceSquatPost((string) $p->slug);
    });

    $restored = $revs->restore($post->fresh(), $revision->fresh());

    expect($restored->slug)->toBe('orig');

    // R-1 — the rolled-back first attempt must leave NO duplicate snapshot and
    // NO leaked event: exactly one PostRestored, exactly one "before restore".
    Event::assertDispatchedTimes(PostRestored::class, 1);
    expect(
        PostRevision::query()
            ->where('post_id', $post->id)
            ->where('label', 'auto: before restore')
            ->count()
    )->toBe(1);
});

// ── Exhaustion — the typed throw, never a raw QueryException ───────────────────

it('throws SlugExhaustedException (never a raw QueryException) when slug collisions exhaust the retry budget on create', function (): void {
    // Persistent squatter — every attempt re-derives the same free slug and
    // re-collides, so the bounded retry budget is exhausted.
    Post::creating(function (Post $post): void {
        slugRaceSquatPost((string) $post->slug);
    });

    expect(fn () => app(PostService::class)->create(['title' => 'Doomed', 'slug' => 'doomed']))
        ->toThrow(SlugExhaustedException::class);
});

// ── R-3 — a non-unique QueryException escapes retryOnCollision unretried ──────

it('lets a non-unique QueryException escape retryOnCollision unretried (R-3)', function (): void {
    $slugs = app(SlugGenerator::class);
    $calls = 0;
    $caught = null;

    // A missing-table error is a QueryException that is NOT a
    // UniqueConstraintViolationException: it must propagate as-is (FK / NOT-NULL
    // / deadlock discipline) and must NOT be retried or relabelled. (A try/catch
    // rather than expect()->toThrow() so the by-ref $calls binds to THIS scope,
    // not an arrow-fn value copy.)
    try {
        $slugs->retryOnCollision(function () use (&$calls): void {
            $calls++;
            DB::select('SELECT * FROM a_table_that_does_not_exist');
        });
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught)->not->toBeInstanceOf(SlugExhaustedException::class)
        ->and($calls)->toBe(1);
});
