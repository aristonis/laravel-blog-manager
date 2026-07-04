<?php

declare(strict_types=1);

use Aristonis\BlogManager\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * M6 — composite (status, published_at) index on blog_posts.
 *
 * The composite covers the `status` prefix, so the old standalone `status`
 * index is redundant and is removed; `published_at`'s standalone index stays
 * because published_at is NOT a prefix of the composite (lookups filtering on
 * published_at alone still need it). Assertions match on the column tuple only,
 * never on the auto-generated index name (non-brittle).
 *
 * @return Collection<int, list<string>>
 */
function postIndexColumnSets(): Collection
{
    /** @var Collection<int, list<string>> $sets */
    $sets = collect(Schema::getIndexes('blog_posts'))->pluck('columns');

    return $sets;
}

it('creates a composite (status, published_at) index on blog_posts (M6)', function () {
    expect(postIndexColumnSets()->contains(fn (array $cols): bool => $cols === ['status', 'published_at']))
        ->toBeTrue();
});

it('drops the now-redundant standalone status index covered by the composite prefix (M6)', function () {
    expect(postIndexColumnSets()->contains(fn (array $cols): bool => $cols === ['status']))
        ->toBeFalse();
});

it('keeps the standalone published_at index, which is not a prefix of the composite (M6)', function () {
    expect(postIndexColumnSets()->contains(fn (array $cols): bool => $cols === ['published_at']))
        ->toBeTrue();
});

it('still paginates published posts correctly after the index change (M6)', function () {
    Carbon::setTestNow('2026-07-02 12:00:00');
    $svc = app(PostService::class);

    $svc->create(['title' => 'A draft']);                                  // draft -> excluded
    $live = $svc->publish($svc->create(['title' => 'Live']), now()->subHour());   // visible
    $svc->publish($svc->create(['title' => 'Scheduled']), now()->addHour());      // future -> excluded

    expect($svc->paginate(15, true)->pluck('public_id')->all())->toBe([$live->public_id]);

    Carbon::setTestNow();
});
