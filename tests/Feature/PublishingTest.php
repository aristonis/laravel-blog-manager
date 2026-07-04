<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\PostStatus;
use Aristonis\BlogManager\Events\PostPublished;
use Aristonis\BlogManager\Events\PostUnpublished;
use Aristonis\BlogManager\Exceptions\PostNotFoundException;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('defaults a new post to draft with no published_at', function () {
    $post = Post::create(['title' => 'Draft me', 'slug' => 'draft-me'])->fresh();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->published_at)->toBeNull();
});

it('casts status to the PostStatus enum and published_at to a datetime', function () {
    $post = Post::create([
        'title' => 'P',
        'slug' => 'p',
        'status' => PostStatus::Published,
        'published_at' => '2026-01-01 00:00:00',
    ])->fresh();

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->toBeInstanceOf(Carbon::class)
        ->and($post->published_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('published scope returns only published posts whose published_at has passed', function () {
    Carbon::setTestNow('2026-07-02 12:00:00');

    $visible = Post::create([
        'title' => 'Visible', 'slug' => 'visible',
        'status' => PostStatus::Published, 'published_at' => now()->subHour(),
    ]);
    Post::create(['title' => 'Draft', 'slug' => 'draft', 'status' => PostStatus::Draft]);
    Post::create([
        'title' => 'Scheduled', 'slug' => 'scheduled',
        'status' => PostStatus::Published, 'published_at' => now()->addHour(),
    ]);

    expect(Post::published()->pluck('public_id')->all())->toBe([$visible->public_id]);

    Carbon::setTestNow();
});

it('draft scope returns only drafts', function () {
    $draft = Post::create(['title' => 'D', 'slug' => 'd', 'status' => PostStatus::Draft]);
    Post::create([
        'title' => 'Pub', 'slug' => 'pub',
        'status' => PostStatus::Published, 'published_at' => now()->subDay(),
    ]);

    expect(Post::draft()->pluck('public_id')->all())->toBe([$draft->public_id]);
});

// --- SG-2: publishing service -------------------------------------------------

it('publishes a draft immediately, marks it visible, and dispatches PostPublished after commit', function () {
    $fired = 0;
    Event::listen(PostPublished::class, function () use (&$fired) {
        $fired++;
    });

    $svc = app(PostService::class);
    $published = $svc->publish($svc->create(['title' => 'Launch']));

    expect($published->status)->toBe(PostStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and(Post::published()->pluck('public_id')->all())->toBe([$published->public_id])
        ->and($fired)->toBe(1);
});

it('schedules a post with a future date, keeping it out of the published scope', function () {
    Carbon::setTestNow('2026-07-02 12:00:00');
    $svc = app(PostService::class);

    $scheduled = $svc->publish($svc->create(['title' => 'Future']), now()->addDay());

    expect($scheduled->status)->toBe(PostStatus::Published)
        ->and($scheduled->published_at->toDateTimeString())->toBe('2026-07-03 12:00:00')
        ->and(Post::published()->count())->toBe(0);

    Carbon::setTestNow();
});

it('unpublishes a post back to draft and dispatches PostUnpublished', function () {
    $fired = 0;
    Event::listen(PostUnpublished::class, function () use (&$fired) {
        $fired++;
    });

    $svc = app(PostService::class);
    $post = $svc->publish($svc->create(['title' => 'Live']));
    expect(Post::published()->count())->toBe(1);

    $draft = $svc->unpublish($post);

    expect($draft->status)->toBe(PostStatus::Draft)
        ->and($draft->published_at)->toBeNull()
        ->and(Post::published()->count())->toBe(0)
        ->and($fired)->toBe(1);
});

it('paginates only published posts when requested', function () {
    Carbon::setTestNow('2026-07-02 12:00:00');
    $svc = app(PostService::class);

    $svc->create(['title' => 'A draft']);
    $live = $svc->publish($svc->create(['title' => 'Live']), now()->subHour());
    $svc->publish($svc->create(['title' => 'Scheduled']), now()->addHour());

    expect($svc->paginate()->total())->toBe(3)
        ->and($svc->paginate(15, true)->pluck('public_id')->all())->toBe([$live->public_id]);

    Carbon::setTestNow();
});

it('breaks a published_at tie by id so published pagination is deterministic across pages', function () {
    Carbon::setTestNow('2026-07-02 12:00:00');
    $svc = app(PostService::class);

    $at = now()->subHour();
    // Two posts share an identical published_at; b has the higher id. Without an
    // id tiebreaker the order of the tie is engine-defined and can skip/duplicate
    // a row across a page boundary.
    $a = $svc->publish($svc->create(['title' => 'A']), $at);
    $b = $svc->publish($svc->create(['title' => 'B']), $at);

    expect($a->published_at->equalTo($b->published_at))->toBeTrue();

    // Single page: the id tiebreaker orders the tie newest-id-first.
    expect($svc->paginate(15, true)->pluck('id')->all())->toBe([$b->id, $a->id]);

    // Across a perPage=1 boundary: page 1 + page 2 cover both rows, no skip/dup.
    Paginator::currentPageResolver(fn () => 1);
    $page1 = $svc->paginate(1, true)->pluck('id')->all();
    Paginator::currentPageResolver(fn () => 2);
    $page2 = $svc->paginate(1, true)->pluck('id')->all();
    Paginator::currentPageResolver(fn () => 1);

    expect($page1)->toBe([$b->id])
        ->and($page2)->toBe([$a->id]);

    Carbon::setTestNow();
});

it('find(onlyPublished) hides a draft addressed by slug', function () {
    $svc = app(PostService::class);
    $draft = $svc->create(['title' => 'Secret', 'slug' => 'secret']);

    expect($svc->find('secret')->public_id)->toBe($draft->public_id);
    expect(fn () => $svc->find('secret', true))->toThrow(PostNotFoundException::class);
});

it('does not dispatch PostPublished when the surrounding transaction rolls back', function () {
    $fired = 0;
    Event::listen(PostPublished::class, function () use (&$fired) {
        $fired++;
    });

    $svc = app(PostService::class);
    $post = $svc->create(['title' => 'Z']);

    try {
        DB::transaction(function () use ($svc, $post) {
            $svc->publish($post);
            throw new RuntimeException('boom');
        });
    } catch (Throwable) {
        // expected
    }

    expect($fired)->toBe(0)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
});
