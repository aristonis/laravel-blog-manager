<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\PostStatus;
use Aristonis\BlogManager\Exceptions\CategoryNotFoundException;
use Aristonis\BlogManager\Exceptions\TagNotFoundException;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\TaxonomyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/*
 * SG-4 reads (§2.6, FR-55/56/57, AC-40, NFR-24). Uniquely-named helpers so the
 * file coexists with (and stays runnable independently of) the other taxonomy
 * suites. Membership is seeded with raw pivot attach() so these read tests do
 * not depend on the SG-3 attach service.
 */

function reads(): TaxonomyService
{
    return app(TaxonomyService::class);
}

/** A fresh draft post with a guaranteed-unique slug. */
function mkPost(string $title = 'Post'): Post
{
    static $n = 0;
    $n++;

    return Post::create(['title' => $title, 'slug' => 'read-post-'.$n]);
}

/** Run $fn with a clean query log and return the number of queries it issued. */
function countQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// ---- postsByCategory / postsByTag (FR-56, AC-40) ------------------------

it('returns only directly-attached posts, newest-first, paginated', function () {
    $news = reads()->createCategory('News');
    $p1 = mkPost();
    $p2 = mkPost();
    $p3 = mkPost(); // never filed under $news — must be excluded
    $news->posts()->attach([$p1->id, $p2->id]);

    $page = reads()->postsByCategory($news);

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(2) // $p3 excluded: direct membership only
        ->and($page->getCollection()->pluck('id')->all())->toBe([$p2->id, $p1->id]); // id-desc = newest-first
});

it('hides drafts and scheduled posts under onlyPublished, shows them otherwise', function () {
    $news = reads()->createCategory('News');
    $live = mkPost('Live');
    $live->update(['status' => PostStatus::Published, 'published_at' => now()->subDay()]);
    $draft = mkPost('Draft'); // status defaults to draft
    $scheduled = mkPost('Scheduled');
    $scheduled->update(['status' => PostStatus::Published, 'published_at' => now()->addDay()]); // future
    $news->posts()->attach([$live->id, $draft->id, $scheduled->id]);

    expect(reads()->postsByCategory($news, onlyPublished: false)->total())->toBe(3); // all members

    $published = reads()->postsByCategory($news, onlyPublished: true);

    expect($published->total())->toBe(1) // draft (status) + scheduled (future) both filtered by scopePublished
        ->and($published->getCollection()->first()->id)->toBe($live->id);
});

it('orders published posts newest-first by published_at, not by id', function () {
    $news = reads()->createCategory('News');
    // id order (A,B,C) deliberately differs from published_at order (C,A,B).
    $a = mkPost('A');
    $a->update(['status' => PostStatus::Published, 'published_at' => now()->subDays(2)]);
    $b = mkPost('B');
    $b->update(['status' => PostStatus::Published, 'published_at' => now()->subDays(3)]); // oldest
    $c = mkPost('C');
    $c->update(['status' => PostStatus::Published, 'published_at' => now()->subDay()]); // newest
    $news->posts()->attach([$a->id, $b->id, $c->id]);

    $page = reads()->postsByCategory($news, onlyPublished: true);

    expect($page->getCollection()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('breaks a published_at tie by id (newest-first) so pagination is stable', function () {
    $news = reads()->createCategory('News');
    $at = now()->subDay();
    // three posts share an identical published_at; only the id tiebreaker makes the
    // order deterministic across page boundaries (no skipped or duplicated row).
    $p1 = mkPost('P1');
    $p1->update(['status' => PostStatus::Published, 'published_at' => $at]);
    $p2 = mkPost('P2');
    $p2->update(['status' => PostStatus::Published, 'published_at' => $at]);
    $p3 = mkPost('P3');
    $p3->update(['status' => PostStatus::Published, 'published_at' => $at]);
    $news->posts()->attach([$p1->id, $p2->id, $p3->id]);

    $page = reads()->postsByCategory($news, onlyPublished: true);

    expect($page->getCollection()->pluck('id')->all())->toBe([$p3->id, $p2->id, $p1->id]);
});

it('lists posts by tag, direct-membership only, newest-first, paginated', function () {
    $php = reads()->createTag('php');
    $p1 = mkPost();
    $p2 = mkPost();
    $other = mkPost(); // untagged
    $php->posts()->attach([$p1->id, $p2->id]);

    $page = reads()->postsByTag($php);

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(2)
        ->and($page->getCollection()->pluck('id')->all())->toBe([$p2->id, $p1->id]);
});

// ---- getCategory / getTag by id-or-slug (FR-57) -------------------------

it('resolves a category by public id or slug', function () {
    $news = reads()->createCategory('Breaking News'); // slug: breaking-news

    expect(reads()->getCategory($news->public_id)->id)->toBe($news->id)
        ->and(reads()->getCategory('breaking-news')->id)->toBe($news->id);
});

it('throws CategoryNotFoundException when no category matches the id or slug', function () {
    reads()->createCategory('News');

    expect(fn () => reads()->getCategory('missing'))->toThrow(CategoryNotFoundException::class);
});

it('resolves a tag by public id or slug', function () {
    $php = reads()->createTag('PHP'); // slug: php

    expect(reads()->getTag($php->public_id)->id)->toBe($php->id)
        ->and(reads()->getTag('php')->id)->toBe($php->id);
});

it('throws TagNotFoundException when no tag matches the id or slug', function () {
    reads()->createTag('php');

    expect(fn () => reads()->getTag('missing'))->toThrow(TagNotFoundException::class);
});

// ---- categories() / tags() flat listing (FR-57) -------------------------

it('lists all categories flat and ordered by name', function () {
    // inserted out of alphabetical order so the assertion proves orderBy('name')
    reads()->createCategory('Gamma');
    reads()->createCategory('Alpha');
    reads()->createCategory('Beta');

    expect(reads()->categories()->pluck('name')->all())->toBe(['Alpha', 'Beta', 'Gamma']);
});

it('lists all tags flat and ordered by name', function () {
    reads()->createTag('zeta');
    reads()->createTag('alpha');
    reads()->createTag('mu');

    expect(reads()->tags()->pluck('name')->all())->toBe(['alpha', 'mu', 'zeta']);
});

// ---- for(Post) both axes (FR-55) ----------------------------------------

it('returns both taxonomy axes for a post', function () {
    $post = mkPost();
    $news = reads()->createCategory('News');
    $php = reads()->createTag('php');
    $post->categories()->attach($news->id);
    $post->tags()->attach($php->id);

    $terms = reads()->for($post);

    expect(array_keys($terms))->toBe(['categories', 'tags'])
        ->and($terms['categories']->pluck('name')->all())->toBe(['News'])
        ->and($terms['tags']->pluck('name')->all())->toBe(['php']);
});

// ---- N+1 guard (NFR-24) -------------------------------------------------

it('reads posts by category with a size-independent query count (no N+1)', function () {
    $few = reads()->createCategory('Few');
    foreach (range(1, 2) as $i) {
        $few->posts()->attach(mkPost()->id);
    }
    $many = reads()->createCategory('Many');
    foreach (range(1, 6) as $i) {
        $many->posts()->attach(mkPost()->id);
    }

    $forFew = countQueries(fn () => reads()->postsByCategory($few));
    $forMany = countQueries(fn () => reads()->postsByCategory($many));

    // Bounded and independent of member count: one count query + one page query.
    expect($forFew)->toBe($forMany)->and($forFew)->toBeLessThanOrEqual(2);
});

it('loads a post\'s terms with a bounded query count (no N+1)', function () {
    $post = mkPost();
    $c1 = reads()->createCategory('C1');
    $c2 = reads()->createCategory('C2');
    $post->categories()->attach([$c1->id, $c2->id]);
    foreach (['t1', 't2', 't3'] as $name) {
        $post->tags()->attach(reads()->createTag($name)->id);
    }

    $fresh = Post::query()->findOrFail($post->id); // relations not yet loaded
    $queries = countQueries(fn () => reads()->for($fresh));

    expect($queries)->toBe(2); // one eager load per axis, regardless of term count
});
