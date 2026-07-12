<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostSeo;
use Aristonis\BlogManager\Seo\ResolvedSeo;
use Aristonis\BlogManager\Services\SeoService;
use Illuminate\Support\Facades\DB;

function resolver(): SeoService
{
    return app(SeoService::class);
}

/** A fresh draft post with a guaranteed-unique slug. */
function seoPost(string $title = 'Post Title'): Post
{
    static $n = 0;
    $n++;

    return Post::create(['title' => $title, 'slug' => 'seo-resolve-'.$n]);
}

/**
 * A paragraph block seated at $position. Structural fields (post_id/type) are set
 * via forceCreate (H3), mirroring the SG-1 data-model test.
 */
function paragraphBlock(Post $post, int $position, string $content, string $format = 'plain'): ContentBlock
{
    return ContentBlock::forceCreate([
        'post_id' => $post->id,
        'type' => 'paragraph',
        'position' => $position,
        'data' => ['content' => $content, 'format' => $format],
    ]);
}

/** Run $fn with a clean query log and return the number of queries it issued. */
function countResolverQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// ── AC-73 / FR-90: the 1.0 host contract lives under the Seo\ namespace ───────
//
// ResolvedSeo was misfiled under Blocks\; SG-4 pins it to Seo\ before the 1.0 tag.
// Assert the new FQN resolves and the old one no longer autoloads.

it('exposes ResolvedSeo under the Seo namespace, not the old Blocks namespace', function () {
    expect(class_exists('Aristonis\\BlogManager\\Seo\\ResolvedSeo'))->toBeTrue()
        ->and(class_exists('Aristonis\\BlogManager\\Blocks\\ResolvedSeo'))->toBeFalse();
});

// ── full fallback chain: stored overrides win ─────────────────────────────────

it('resolves every field from the stored overrides when all are set', function () {
    $post = seoPost('Bare Post Title');

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

    $resolved = resolver()->resolve($post);

    expect($resolved)->toBeInstanceOf(ResolvedSeo::class)
        ->and($resolved->title)->toBe('Meta Title')
        ->and($resolved->description)->toBe('Meta description text.')
        ->and($resolved->canonicalUrl)->toBe('https://example.test/canonical')
        ->and($resolved->noindex)->toBeTrue()
        ->and($resolved->nofollow)->toBeTrue()
        ->and($resolved->ogTitle)->toBe('OG Title')
        ->and($resolved->ogDescription)->toBe('OG description text.')
        ->and($resolved->ogImage)->toBe('https://example.test/og.png')
        ->and($resolved->ogType)->toBe('website');
});

// ── D-c refinement: og_title never overrides the page <title> ─────────────────

it('lets og_title drive only ogTitle, never the page title (D-c)', function () {
    $post = seoPost('Canonical Page Title');

    // og_title set, meta_title deliberately unset — the page <title> must still
    // fall back to the post title, NOT the social title.
    $post->seo()->create(['og_title' => 'Social Share Title']);

    $resolved = resolver()->resolve($post);

    expect($resolved->title)->toBe('Canonical Page Title')
        ->and($resolved->ogTitle)->toBe('Social Share Title');
});

// ── full fallback: nothing stored ─────────────────────────────────────────────

it('falls back to the post title and derived chain when no SEO row exists', function () {
    $post = seoPost('Only The Post Title');
    paragraphBlock($post, 0, 'A short intro paragraph.');

    $resolved = resolver()->resolve($post);

    expect($resolved->title)->toBe('Only The Post Title')
        ->and($resolved->description)->toBe('A short intro paragraph.')
        ->and($resolved->canonicalUrl)->toBeNull()
        ->and($resolved->noindex)->toBeFalse()
        ->and($resolved->nofollow)->toBeFalse()
        ->and($resolved->ogTitle)->toBe('Only The Post Title')      // = title
        ->and($resolved->ogDescription)->toBe('A short intro paragraph.') // = description
        ->and($resolved->ogImage)->toBeNull()
        ->and($resolved->ogType)->toBe('article');                  // config default
});

it('resolves ogDescription from the excerpt when only meta_description is unset', function () {
    $post = seoPost('Post');
    paragraphBlock($post, 0, 'Derived body excerpt.');

    // No description overrides at all — both description and ogDescription derive.
    $resolved = resolver()->resolve($post);

    expect($resolved->description)->toBe('Derived body excerpt.')
        ->and($resolved->ogDescription)->toBe('Derived body excerpt.');
});

// ── og_type: config-driven default flips; stored value wins ───────────────────

it('flips the resolved og_type default when the config default changes', function () {
    $post = seoPost('Post');

    expect(resolver()->resolve($post)->ogType)->toBe('article');

    config()->set('blog-manager.seo.default_og_type', 'website');

    expect(resolver()->resolve($post)->ogType)->toBe('website');
});

it('prefers a stored og_type over the config default', function () {
    $post = seoPost('Post');
    $post->seo()->create(['og_type' => 'book']);

    config()->set('blog-manager.seo.default_og_type', 'website');

    expect(resolver()->resolve($post)->ogType)->toBe('book');
});

// ── purity (NFR-26): no writes, idempotent ────────────────────────────────────

it('performs no writes and returns an identical DTO on repeated resolves', function () {
    $post = seoPost('Idempotent Post');
    paragraphBlock($post, 0, 'Body text for the excerpt.');

    $before = PostSeo::query()->count();

    $first = resolver()->resolve($post);
    $second = resolver()->resolve($post);

    expect(PostSeo::query()->count())->toBe($before)          // no seo row created
        ->and(PostSeo::query()->where('post_id', $post->id)->exists())->toBeFalse()
        ->and($second->toArray())->toBe($first->toArray());   // deterministic
});

// ── excerpt derivation (O-2) ──────────────────────────────────────────────────

it('derives the excerpt from the first paragraph, ignoring non-paragraph and later blocks', function () {
    $post = seoPost('Post');

    // A non-paragraph block at the lowest position and a later paragraph must both
    // be ignored — only the first paragraph feeds the excerpt.
    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'image', 'position' => 0, 'data' => ['alt' => 'cover']]);
    paragraphBlock($post, 1, 'The real first paragraph.');
    paragraphBlock($post, 2, 'A later paragraph that must be ignored.');

    expect(resolver()->resolve($post)->description)->toBe('The real first paragraph.');
});

it('strips markdown syntax from a markdown paragraph excerpt', function () {
    $post = seoPost('Post');
    paragraphBlock(
        $post,
        0,
        "## Heading\n\nSome **bold** words and a [link](https://example.test) here.",
        'markdown',
    );

    $description = resolver()->resolve($post)->description;

    expect($description)->toContain('bold')
        ->and($description)->toContain('link')
        ->and($description)->not->toContain('**')
        ->and($description)->not->toContain('##')
        ->and($description)->not->toContain('](');
});

it('truncates the excerpt mb-safely on a word boundary', function () {
    config()->set('blog-manager.seo.excerpt_length', 8);

    $post = seoPost('Post');
    // Accented characters — a byte-based substring would split é/ö; Str::limit is
    // character-safe and preserves the word boundary.
    paragraphBlock($post, 0, 'Héllo wörld café');

    expect(resolver()->resolve($post)->description)->toBe('Héllo...');
});

it('yields a null description for a whitespace-only paragraph', function () {
    $post = seoPost('Post');
    paragraphBlock($post, 0, "   \n\t  ");

    expect(resolver()->resolve($post)->description)->toBeNull();
});

it('yields a null description when the post has no paragraph block', function () {
    $post = seoPost('Post');
    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'image', 'position' => 0, 'data' => ['alt' => 'cover']]);

    expect(resolver()->resolve($post)->description)->toBeNull();
});

// ── toArray(): exact symmetric nested shape (§3.3) ────────────────────────────

it('serializes to the exact symmetric nested toArray shape', function () {
    $post = seoPost('Fallback Title');

    $post->seo()->create([
        'meta_title' => 'The Title',
        'meta_description' => 'The description.',
        'canonical_url' => 'https://example.test/c',
        'noindex' => true,
        'nofollow' => false,
        'og_title' => 'OG',
        'og_description' => 'OG desc.',
        'og_image' => 'https://example.test/i.png',
        'og_type' => 'website',
    ]);

    expect(resolver()->resolve($post)->toArray())->toBe([
        'title' => 'The Title',
        'description' => 'The description.',
        'canonicalUrl' => 'https://example.test/c',
        'robots' => ['noindex' => true, 'nofollow' => false],
        'og' => [
            'title' => 'OG',
            'description' => 'OG desc.',
            'image' => 'https://example.test/i.png',
            'type' => 'website',
        ],
    ]);
});

// ── AC-47 / NFR-28: bounded feed queries pinned to the NO-description case ─────
//
// The excerpt fallback is the COMMON feed case (no meta/og description), so a host
// eager-loading ->with(['seo','firstParagraph']) must resolve N posts in a
// constant, size-independent number of queries — the excerpt reads the pre-loaded
// firstParagraph, never lazy-loading the block tree per post.

it('resolves a no-description feed in a constant, size-independent query count', function () {
    $feed = function (int $n): int {
        // Each post has a paragraph but NO seo row → the excerpt path is exercised.
        $posts = collect(range(1, $n))->map(function () {
            $post = seoPost('Feed Post');
            paragraphBlock($post, 0, 'Feed body paragraph.');

            return $post;
        });

        return countResolverQueries(function () use ($posts) {
            $ids = $posts->pluck('id');
            $loaded = Post::query()
                ->whereIn('id', $ids)
                ->with(['seo', 'firstParagraph'])
                ->get();

            $service = resolver();
            foreach ($loaded as $post) {
                $service->resolve($post);
            }
        });
    };

    $small = $feed(2);
    $large = $feed(8);

    // Size-independent (no N+1) AND bounded to the eager-load recipe:
    // SELECT posts + SELECT seo + SELECT firstParagraph = 3, plus 0 per resolve.
    expect($small)->toBe($large)
        ->and($small)->toBe(3);
});
