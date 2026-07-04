<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * SG-7 addendum — explicit indexes on the two FK columns Postgres does not
 * auto-index. On MySQL/InnoDB a `foreignId()->constrained()` auto-indexes the
 * child column; on PostgreSQL (and SQLite) it does NOT, so the column must be
 * indexed explicitly — as the pivots already do. These two were missed.
 * Assertions match on the column tuple only, never on the auto-generated index
 * name (non-brittle), mirroring PostIndexTest.
 *
 * @return Collection<int, list<string>>
 */
function fkIndexColumnSets(string $table): Collection
{
    /** @var Collection<int, list<string>> $sets */
    $sets = collect(Schema::getIndexes($table))->pluck('columns');

    return $sets;
}

it('indexes post_revisions.post_id as a composite (post_id, id) for prune (SG-7)', function () {
    // RevisionService::prune runs WHERE post_id = ? ORDER BY id DESC LIMIT k;
    // the composite serves it as an index range + reverse scan on both engines.
    expect(fkIndexColumnSets('blog_post_revisions')->contains(fn (array $cols): bool => $cols === ['post_id', 'id']))
        ->toBeTrue();
});

it('indexes content_blocks.media_item_id for the orphan scan and nullOnDelete cascade (SG-7)', function () {
    // MediaManager::orphaned()'s anti-join and the nullOnDelete cascade on every
    // media delete both scan this FK column.
    expect(fkIndexColumnSets('blog_content_blocks')->contains(fn (array $cols): bool => $cols === ['media_item_id']))
        ->toBeTrue();
});
