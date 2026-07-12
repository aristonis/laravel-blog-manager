<?php

declare(strict_types=1);

use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Tests\TestCase;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SG-5 (FR-91) — EXPLAIN smoke, driver-only (AC-76/77).
 *
 * SQLite (local + the main CI matrix) cannot prove an index is chosen — its
 * planner and EXPLAIN output are not comparable — so every test here auto-skips
 * on SQLite via {@see beforeEach()} and runs only on the PG/MySQL CI legs.
 *
 * Intent (per driver, same meaning): the hot read paths stay index-driven, i.e.
 * they do NOT fall back to a full table scan on the target table. This is a
 * SMOKE test — it guards against an accidental full-scan rewrite of a hot path;
 * it is NOT a proof of planner behavior at production scale. The seeds are kept
 * deliberately small (fast tests), so a passing run says "the planner did not
 * choose a full scan here", not "the index is optimal under production load".
 * AC-76/77 are worded in Postgres terms ("no Seq Scan"); MySQL's equivalent is
 * "the access `type` is not `ALL`". We branch on the driver name and assert the
 * same intent.
 *
 * CI-ONLY: these assertions cannot be verified offline (no PG/MySQL containers);
 * the exact planner strings are validated on GitHub Actions' `databases` job.
 */
uses(TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('requires a real DB driver (mysql/pgsql)');
    }
});

/**
 * How many revisions the prune access path keeps (mirrors the shipped default).
 */
const PRUNE_KEEP = 20;

/**
 * Assert the given query does not full-scan $table on the current driver.
 *
 * Postgres: the plan text carries no `Seq Scan on <table>`. MySQL: no EXPLAIN row
 * for <table> has access `type = ALL`. Bindings are forwarded so a parameterised
 * WHERE is planned exactly as the service would run it.
 *
 * @param  Builder  $query
 */
function assertIndexDriven($query, string $table): void
{
    $rows = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());

    if (DB::connection()->getDriverName() === 'pgsql') {
        $plan = collect($rows)
            ->map(fn (object $row): array => (array) $row)
            ->flatten()
            ->implode("\n");

        expect($plan)->not->toContain("Seq Scan on {$table}");

        return;
    }

    // MySQL: inspect each plan row; the target table must not be accessed via ALL.
    // First fail loud if the target table never appears in the plan — otherwise
    // the per-row loop below would assert nothing and pass vacuously (e.g. if a
    // rewrite renamed the table or optimised it out).
    $referencesTable = collect($rows)
        ->contains(fn (object $row): bool => (((array) $row)['table'] ?? null) === $table);

    expect($referencesTable)->toBeTrue("EXPLAIN plan never references table [{$table}]");

    foreach ($rows as $row) {
        $columns = (array) $row;
        if (($columns['table'] ?? null) === $table) {
            expect(strtoupper((string) ($columns['type'] ?? '')))->not->toBe('ALL');
        }
    }
}

it('keeps RevisionService::prune index-driven on blog_post_revisions (AC-76)', function () {
    // Many posts, one target with a modest share of the rows, so `post_id = ?`
    // is selective and the composite (post_id, id) index beats a full scan.
    $target = Post::create(['title' => 'target', 'slug' => 'prune-target']);
    seedPosts(39, startIndex: 1);
    seedRevisions($target->id, count: 30);
    for ($postId = 2; $postId <= 40; $postId++) {
        seedRevisions($postId, count: 25);
    }
    analyzeTables(['blog_post_revisions']);

    // The prune "keep the newest N" access path: WHERE post_id = ? ORDER BY id DESC LIMIT N.
    $query = $target->revisions()->select('id')->orderByDesc('id')->limit(PRUNE_KEEP);

    assertIndexDriven($query, 'blog_post_revisions');
});

it('keeps the orphaned anti-join index-driven on blog_content_blocks (AC-77)', function () {
    // A small media set (tiny outer scan) against a larger, indexed block set biases
    // the planner to a nested-loop anti-join that probes content_blocks.media_item_id
    // by index instead of hashing a full seq scan of the blocks table.
    seedMedia(30);
    seedPosts(4, startIndex: 1);
    seedBlocks(posts: 4, perPost: 200, referencedMedia: 25);
    analyzeTables(['blog_content_blocks', 'blog_media_items']);

    $manager = app(MediaManager::class);
    $method = new ReflectionMethod($manager, 'orphanedQuery');
    $method->setAccessible(true);

    /** @var Builder $query */
    $query = $method->invoke($manager);

    assertIndexDriven($query, 'blog_content_blocks');
});

/**
 * Bulk-insert $count posts with sequential slugs, ids starting at $startIndex.
 */
function seedPosts(int $count, int $startIndex): void
{
    $now = Carbon::now();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $n = $startIndex + $i;
        $rows[] = [
            'public_id' => (string) Str::ulid(),
            'title' => "post {$n}",
            'slug' => "seed-post-{$n}",
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('blog_posts')->insert($rows);
}

/**
 * Bulk-insert $count revisions for $postId.
 */
function seedRevisions(int $postId, int $count): void
{
    $now = Carbon::now();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'public_id' => (string) Str::ulid(),
            'post_id' => $postId,
            'snapshot' => json_encode(['title' => "rev {$i}"]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('blog_post_revisions')->insert($rows);
}

/**
 * Insert $count media items via the model (auto-mints public_id).
 */
function seedMedia(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        MediaItem::forceCreate([
            'kind' => 'image',
            'mime' => 'image/png',
            'size' => 10,
            'original_filename' => "m{$i}.png",
            'adapter' => 'filesystem',
            'disk' => 'public',
            'path' => "blog-media/m{$i}.png",
        ]);
    }
}

/**
 * Bulk-insert $perPost blocks for each of $posts posts, cycling references over
 * the first $referencedMedia media ids (the remainder stay orphaned).
 */
function seedBlocks(int $posts, int $perPost, int $referencedMedia): void
{
    $now = Carbon::now();
    $rows = [];
    for ($postId = 1; $postId <= $posts; $postId++) {
        for ($position = 0; $position < $perPost; $position++) {
            $rows[] = [
                'public_id' => (string) Str::ulid(),
                'post_id' => $postId,
                'type' => 'image',
                'position' => $position,
                'media_item_id' => ($position % $referencedMedia) + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }
    DB::table('blog_content_blocks')->insert($rows);
}

/**
 * Refresh planner statistics so the index-vs-scan decision reflects the seed.
 *
 * @param  list<string>  $tables
 */
function analyzeTables(array $tables): void
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement('ANALYZE');

        return;
    }

    foreach ($tables as $table) {
        DB::statement("ANALYZE TABLE {$table}");
    }
}
