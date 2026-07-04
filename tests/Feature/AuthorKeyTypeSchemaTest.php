<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SG-1 (H4) — schema introspection for the configurable author key type.
 *
 * The base harness migrates with the default (`bigint`) config in setUp. To
 * exercise the other keys we re-run just the two author-bearing migrations
 * (posts + revisions) after setting the config — config is read at migrate
 * time, so it must be in place BEFORE `up()` runs. SQLite reports uuid/ulid
 * columns via string affinity (R-8), so the byte-for-byte ULID round-trip is
 * the authoritative proof that no int-coercion/truncation occurs (AC-57).
 */

/**
 * Re-run the two author-bearing migrations under $type. A null $type removes
 * the config key so the resolver's own `bigint` default is exercised. The
 * child (revisions) table is dropped before its parent (posts) to respect the
 * FK; both are empty so the drop is safe even with FK enforcement on.
 */
function migrateAuthorKeyType(?string $type): void
{
    if ($type === null) {
        $config = config('blog-manager');
        unset($config['author_key_type']);
        config()->set('blog-manager', $config);
    } else {
        config()->set('blog-manager.author_key_type', $type);
    }

    Schema::dropIfExists('blog_post_revisions');
    Schema::dropIfExists('blog_posts');

    $base = __DIR__.'/../../database/migrations';
    (require $base.'/2026_07_01_000001_create_blog_posts_table.php')->up();
    (require $base.'/2026_07_01_000004_create_blog_post_revisions_table.php')->up();
}

/**
 * The driver-reported type name for a column, or null when the column is
 * absent (used to assert a table stayed un-created after a fail-loud throw).
 */
function authorKeyColumnType(string $table, string $column): ?string
{
    $col = collect(Schema::getColumns($table))->firstWhere('name', $column);

    return is_array($col) ? ($col['type_name'] ?? null) : null;
}

/**
 * @return Collection<int, list<string>>
 */
function authorKeyIndexSets(string $table): Collection
{
    /** @var Collection<int, list<string>> $sets */
    $sets = collect(Schema::getIndexes($table))->pluck('columns');

    return $sets;
}

/**
 * @return Collection<int, list<string>>
 */
function authorKeyForeignKeySets(string $table): Collection
{
    /** @var Collection<int, list<string>> $sets */
    $sets = collect(Schema::getForeignKeys($table))->pluck('columns');

    return $sets;
}

it('default install: author_id is unsignedBigInteger, nullable, indexed, no FK (AC-53)', function () {
    migrateAuthorKeyType(null); // key absent -> resolver default `bigint`

    $col = collect(Schema::getColumns('blog_posts'))->firstWhere('name', 'author_id');

    expect($col['type_name'])->toBe('integer')          // SQLite affinity for unsignedBigInteger
        ->and($col['nullable'])->toBeTrue()
        ->and(authorKeyIndexSets('blog_posts')->contains(fn (array $c): bool => $c === ['author_id']))->toBeTrue()
        ->and(authorKeyForeignKeySets('blog_posts')->contains(fn (array $c): bool => in_array('author_id', $c, true)))->toBeFalse();
});

it('explicit bigint is byte-for-byte identical to the default (NFR-30, AC-53)', function () {
    migrateAuthorKeyType(null);
    $default = authorKeyColumnType('blog_posts', 'author_id');

    migrateAuthorKeyType('bigint');
    $explicit = authorKeyColumnType('blog_posts', 'author_id');

    expect($explicit)->toBe($default)->toBe('integer');
});

it('uuid config gives author_id a string column distinct from bigint (AC-54)', function () {
    migrateAuthorKeyType('bigint');
    $bigint = authorKeyColumnType('blog_posts', 'author_id');

    migrateAuthorKeyType('uuid');
    $uuid = authorKeyColumnType('blog_posts', 'author_id');

    expect($uuid)->not->toBe($bigint)->toBe('varchar');
});

it('ulid config gives author_id a string column distinct from bigint (AC-54)', function () {
    migrateAuthorKeyType('bigint');
    $bigint = authorKeyColumnType('blog_posts', 'author_id');

    migrateAuthorKeyType('ulid');
    $ulid = authorKeyColumnType('blog_posts', 'author_id');

    expect($ulid)->not->toBe($bigint)->toBe('varchar');
});

it('created_by column type matches author_id for every key, with no standalone index (AC-55, FR-77)', function (?string $type) {
    migrateAuthorKeyType($type);

    expect(authorKeyColumnType('blog_post_revisions', 'created_by'))
        ->toBe(authorKeyColumnType('blog_posts', 'author_id'))
        ->and(authorKeyIndexSets('blog_post_revisions')->contains(fn (array $c): bool => $c === ['created_by']))
        ->toBeFalse();
})->with([null, 'bigint', 'uuid', 'ulid']);

it('fails loud on an unknown value before Schema::create, leaving the table absent (AC-56)', function () {
    config()->set('blog-manager.author_key_type', 'int');
    Schema::dropIfExists('blog_post_revisions');
    Schema::dropIfExists('blog_posts');

    $migration = require __DIR__.'/../../database/migrations/2026_07_01_000001_create_blog_posts_table.php';

    expect(fn () => $migration->up())->toThrow(
        InvalidArgumentException::class,
        'Invalid blog-manager.author_key_type [int]; allowed: bigint, uuid, ulid.',
    );

    expect(Schema::hasTable('blog_posts'))->toBeFalse();
});

it('round-trips a 26-char ULID on author_id and created_by byte-for-byte (AC-57)', function () {
    migrateAuthorKeyType('ulid');

    $author = (string) Str::ulid();
    expect($author)->toHaveLength(26);

    $post = Post::create(['title' => 'Ulid host', 'slug' => 'ulid-host', 'author_id' => $author]);
    expect($post->fresh()->author_id)->toBe($author); // no int-cast / truncation

    $revision = PostRevision::forceCreate([
        'post_id' => $post->id,
        'public_id' => (string) Str::ulid(),
        'snapshot' => ['title' => 'Ulid host'],
        'created_by' => $author,
    ]);
    expect($revision->fresh()->created_by)->toBe($author);
});
