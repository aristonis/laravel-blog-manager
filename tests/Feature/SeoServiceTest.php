<?php

declare(strict_types=1);

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Events\PostSeoUpdated;
use Aristonis\BlogManager\Exceptions\AuthorizationDeniedException;
use Aristonis\BlogManager\Exceptions\InvalidSeoDataException;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostSeo;
use Aristonis\BlogManager\Seo\ResolvedSeo;
use Aristonis\BlogManager\Services\SeoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

// Flush model event listeners after each test so a `creating` squatter listener
// registered by the concurrency test cannot bleed into the next test.
afterEach(fn () => PostSeo::flushEventListeners());

function seoService(): SeoService
{
    return app(SeoService::class);
}

// ── set(): full upsert + read-back (AC-44) ────────────────────────────────────

it('set() upserts all nine SEO fields and for() reads them back', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $seo = seoService()->set($post, [
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

    expect($seo)->toBeInstanceOf(PostSeo::class);

    $read = seoService()->for($post);

    expect($read)->not->toBeNull()
        ->and($read->meta_title)->toBe('Meta Title')
        ->and($read->meta_description)->toBe('Meta description text.')
        ->and($read->canonical_url)->toBe('https://example.test/canonical')
        ->and($read->noindex)->toBeTrue()
        ->and($read->nofollow)->toBeTrue()
        ->and($read->og_title)->toBe('OG Title')
        ->and($read->og_description)->toBe('OG description text.')
        ->and($read->og_image)->toBe('https://example.test/og.png')
        ->and($read->og_type)->toBe('website');
});

// ── AC-52: set() = full replace — an omitted field is cleared ─────────────────

it('set() clears a previously-set field that is omitted on the next set (AC-52)', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    seoService()->set($post, [
        'meta_title' => 'First',
        'og_image' => 'https://example.test/og.png',
        'noindex' => true,
    ]);

    // Re-set omitting og_image / noindex → they reset to their defaults.
    seoService()->set($post, ['meta_title' => 'Second']);

    $read = seoService()->for($post);

    expect($read->meta_title)->toBe('Second')
        ->and($read->og_image)->toBeNull()
        ->and($read->noindex)->toBeFalse()
        // still a single upserted row, never a second insert
        ->and(PostSeo::query()->where('post_id', $post->id)->count())->toBe(1);
});

// ── update(): partial — omitted fields untouched ──────────────────────────────

it('update() changes only the provided keys and leaves the rest untouched', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    seoService()->set($post, [
        'meta_title' => 'Original Title',
        'og_image' => 'https://example.test/og.png',
        'noindex' => true,
    ]);

    seoService()->update($post, ['meta_title' => 'Patched Title']);

    $read = seoService()->for($post);

    expect($read->meta_title)->toBe('Patched Title')
        ->and($read->og_image)->toBe('https://example.test/og.png') // untouched
        ->and($read->noindex)->toBeTrue();                           // untouched
});

// ── validation caps (AC-45) — fail-loud, no partial write ─────────────────────

it('rejects an over-cap string field fail-loud and writes nothing', function (string $field, string $value) {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    expect(fn () => seoService()->set($post, [$field => $value]))
        ->toThrow(InvalidSeoDataException::class);

    expect(PostSeo::query()->where('post_id', $post->id)->exists())->toBeFalse();
})->with([
    'meta_title > 255' => ['meta_title', str_repeat('a', 256)],
    'meta_description > 500' => ['meta_description', str_repeat('a', 501)],
    'canonical_url > 2048' => ['canonical_url', 'https://x.test/'.str_repeat('a', 2048)],
    'og_image > 2048' => ['og_image', 'https://x.test/'.str_repeat('a', 2048)],
    'og_type > 64' => ['og_type', str_repeat('a', 65)],
    'og_title > 255' => ['og_title', str_repeat('a', 256)],
    'og_description > 500' => ['og_description', str_repeat('a', 501)],
]);

it('rejects a non-string value where a string field is expected', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    expect(fn () => seoService()->set($post, ['og_type' => ['not', 'a', 'string']]))
        ->toThrow(InvalidSeoDataException::class);
});

// ── trim / empty→null (foresight MED-5) ───────────────────────────────────────

it('stores an empty or whitespace-only string field as null and trims the rest', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    seoService()->set($post, [
        'meta_title' => '   ',
        'meta_description' => '',
        'og_title' => '  spaced  ',
    ]);

    $read = seoService()->for($post);

    expect($read->meta_title)->toBeNull()
        ->and($read->meta_description)->toBeNull()
        ->and($read->og_title)->toBe('spaced');
});

// ── unknown-key policy: strict fail-loud (judgment call) ──────────────────────

it('rejects an unknown SEO field fail-loud (strict — never silently dropped)', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    expect(fn () => seoService()->set($post, ['metatitle' => 'typo']))
        ->toThrow(InvalidSeoDataException::class);

    expect(PostSeo::query()->where('post_id', $post->id)->exists())->toBeFalse();
});

// ── guard matrix (AC-49) ──────────────────────────────────────────────────────

it('denies set() and update() without blog.post.update when enforce_in_services is on', function () {
    config()->set('blog-manager.authorization.driver', 'gate'); // denies without a policy
    config()->set('blog-manager.authorization.enforce_in_services', true);

    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    expect(fn () => seoService()->set($post, ['meta_title' => 'X']))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => seoService()->update($post, ['meta_title' => 'X']))
        ->toThrow(AuthorizationDeniedException::class);

    // the guard short-circuits before the transaction: nothing was written
    expect(PostSeo::query()->where('post_id', $post->id)->exists())->toBeFalse();
});

it('permits set() and update() when blog.post.update is granted under enforce', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    config()->set('blog-manager.authorization.enforce_in_services', true);
    Gate::define(Abilities::POST_UPDATE, fn ($user = null) => true); // pins the exact ability

    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    seoService()->set($post, ['meta_title' => 'Granted']);
    seoService()->update($post, ['meta_description' => 'Patched']);

    $read = seoService()->for($post);

    expect($read->meta_title)->toBe('Granted')
        ->and($read->meta_description)->toBe('Patched');
});

it('never guards for(): reads succeed even when blog.post.update is denied', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    config()->set('blog-manager.authorization.enforce_in_services', true);
    // blog.post.update deliberately NOT granted — writes would be denied, reads stay open.

    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $post->seo()->create(['meta_title' => 'Seeded']); // direct persistence, bypasses the service guard

    expect(seoService()->for($post))->not->toBeNull()
        ->and(seoService()->for($post)->meta_title)->toBe('Seeded');
});

it('never guards resolve(): resolution succeeds even when blog.post.update is denied', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    config()->set('blog-manager.authorization.enforce_in_services', true);
    // blog.post.update deliberately NOT granted — writes would be denied, reads stay open.

    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);
    $post->seo()->create(['meta_title' => 'Seeded']); // direct persistence, bypasses the service guard

    $resolved = seoService()->resolve($post);

    expect($resolved)->toBeInstanceOf(ResolvedSeo::class)
        ->and($resolved->title)->toBe('Seeded'); // meta_title ?? post.title
});

// ── after-commit event (AC-48) ────────────────────────────────────────────────

it('dispatches PostSeoUpdated carrying the post after commit on set() and update()', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $fired = 0;
    Event::listen(PostSeoUpdated::class, function (PostSeoUpdated $e) use (&$fired, $post) {
        expect($e->post->id)->toBe($post->id);
        $fired++;
    });

    seoService()->set($post, ['meta_title' => 'X']);
    seoService()->update($post, ['meta_description' => 'Y']);

    expect($fired)->toBe(2);
});

it('does not dispatch PostSeoUpdated when the surrounding transaction rolls back', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $fired = 0;
    Event::listen(PostSeoUpdated::class, function () use (&$fired) {
        $fired++;
    });

    try {
        DB::transaction(function () use ($post) {
            seoService()->set($post, ['meta_title' => 'X']);
            throw new RuntimeException('boom');
        });
    } catch (Throwable) {
        // expected — the outer rollback must discard the after-commit dispatch
    }

    expect($fired)->toBe(0)
        ->and(PostSeo::query()->where('post_id', $post->id)->exists())->toBeFalse();
});

it('does not dispatch PostSeoUpdated when validation fails', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $fired = 0;
    Event::listen(PostSeoUpdated::class, function () use (&$fired) {
        $fired++;
    });

    expect(fn () => seoService()->set($post, ['og_type' => str_repeat('a', 65)]))
        ->toThrow(InvalidSeoDataException::class);

    expect($fired)->toBe(0);
});

// ── concurrent first-write unique(post_id) retry (foresight MED-4) ────────────
//
// DETERMINISTIC SIMULATION (single-threaded, SQLite) — mirrors M1 BlockConcurrency.
// A `creating` model event seats a squatter seo row at the same post_id INSIDE the
// current transaction (once). updateOrCreate's own INSERT then trips
// unique(post_id) → UniqueConstraintViolationException → the transaction rolls back
// (the squatter vanishes with it). The service must CATCH that and retry as an
// upsert rather than leak a raw QueryException.
//
// LIMITATION: SQLite cannot keep the racing row committed across the rollback, so
// this proves the catch+retry path and the single-row outcome (M1 precedent), not
// a true two-connection race.
//
it('recovers from a concurrent first-write unique(post_id) violation by retrying as an upsert', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $table = config('blog-manager.tables.post_seo', 'blog_post_seo');
    $injectedOnce = false;

    PostSeo::creating(function (PostSeo $seo) use (&$injectedOnce, $table) {
        if ($injectedOnce) {
            return; // let subsequent (retry) inserts proceed cleanly
        }
        $injectedOnce = true;

        // Raw builder insert does NOT re-fire PostSeo::creating → no recursion.
        DB::table($table)->insert([
            'post_id' => $seo->post_id, // same slot updateOrCreate is about to use
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    });

    // Pre-retry: a raw QueryException would escape → this assignment throws → RED.
    // Post-retry: the violation is caught, re-read finds no row (squatter rolled
    // back), and the upsert inserts cleanly → GREEN.
    $seo = seoService()->set($post, ['meta_title' => 'Raced']);

    expect($seo)->toBeInstanceOf(PostSeo::class)
        ->and(PostSeo::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(seoService()->for($post)->meta_title)->toBe('Raced');
});
