<?php

declare(strict_types=1);

use Aristonis\BlogManager\Exceptions\BlockPositionConflictException;
use Aristonis\BlogManager\Exceptions\BlockPositionOutOfRangeException;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\BlockService;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Flush Eloquent model event listeners after each test so `creating` squatter
// listeners registered in one test cannot bleed into the next within the same
// dispatcher scope.
afterEach(fn () => ContentBlock::flushEventListeners());

// ── helpers (names are unique to avoid redeclaration across the suite) ────────

function concurrencyTestPost(): Post
{
    return app(PostService::class)->create(['title' => 'Concurrency Post']);
}

function concurrencyBlockService(): BlockService
{
    return app(BlockService::class);
}

// ── Test 1: single collision → retry succeeds ─────────────────────────────────
//
// DETERMINISTIC COLLISION SIMULATION
//
// A `creating` model event inserts a "squatter" row at the exact (post_id,
// position) that append() is about to use — inside the SAME transaction.
// The enclosing ContentBlock::forceCreate() then trips the
// unique(post_id, position) constraint, raising a QueryException.
// The transaction rolls back (squatter disappears with it).
//
// RED  (pre-fix): old append() re-throws that raw QueryException → test fails
//                 because it expects the call to return a block, not throw.
// GREEN (post-fix): the retry loop catches the 23xxx SQLSTATE, re-reads count()
//                   (still N — squatter was rolled back), and succeeds on
//                   attempt 2 because $injectedOnce is already true.
//
it('retries and succeeds when a single position collision occurs on append', function (): void {
    $post = concurrencyTestPost();

    $injectedOnce = false;

    ContentBlock::creating(function (ContentBlock $block) use (&$injectedOnce): void {
        if ($injectedOnce) {
            // Squatter was already injected once; let all subsequent
            // forceCreate attempts proceed normally.
            return;
        }
        $injectedOnce = true;

        // Seat a squatter at the same (post_id, position) inside the current
        // transaction via the query builder.  DB::table insert does NOT fire
        // ContentBlock::creating, so there is no recursion.
        DB::table(config('blog-manager.tables.content_blocks', 'blog_content_blocks'))->insert([
            'public_id' => (string) Str::ulid(),
            'post_id' => $block->post_id,
            'type' => 'paragraph',
            'position' => $block->position,   // ← same slot append() chose
            'data' => json_encode(['content' => 'squatter']),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        // The transaction now has two rows competing for (post_id=X, position=N).
        // The forthcoming INSERT from forceCreate() will hit the unique constraint.
    });

    // Pre-fix: throws raw QueryException → assertion fails → RED
    // Post-fix: retry catches the violation, re-reads count(), inserts cleanly → GREEN
    $block = concurrencyBlockService()->append($post, 'paragraph', ['content' => 'raced']);

    $fresh = $post->fresh()->blocks;

    expect($block->post_id)->toBe($post->id)
        ->and($fresh->count())->toBe(1)
        ->and($fresh->first()->public_id)->toBe($block->public_id)
        ->and($fresh->pluck('position')->all())->toBe([0]); // contiguous 0..0
});

// ── Test 2: every retry collides → typed exception, never a raw QueryException ─
//
// When a persistent unique conflict exhausts all retries, the package must
// surface a typed BlogManagerException subclass — not a raw QueryException.
//
// RED  (pre-fix): raw QueryException escapes on the very first attempt.
//                 toThrow(BlogManagerException::class) fails because QueryException
//                 does not extend BlogManagerException.
// GREEN (post-fix): all retries fail → BlockPositionConflictException (extends
//                   BlogManagerException) is thrown → assertion passes.
//
it('throws a typed BlogManagerException when all append retries are exhausted', function (): void {
    $post = concurrencyTestPost();

    // Always inject a squatter — no $injectedOnce guard — so every attempt
    // trips the constraint.  DB::table insert never re-fires ContentBlock::creating.
    ContentBlock::creating(function (ContentBlock $block): void {
        DB::table(config('blog-manager.tables.content_blocks', 'blog_content_blocks'))->insert([
            'public_id' => (string) Str::ulid(),
            'post_id' => $block->post_id,
            'type' => 'paragraph',
            'position' => $block->position,
            'data' => json_encode(['content' => 'persistent squatter']),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    });

    // Pre-fix: raw QueryException escapes → fails (wrong type).
    // Post-fix: BlockPositionConflictException thrown → passes.
    expect(fn () => concurrencyBlockService()->append($post, 'paragraph', ['content' => 'exhaust']))
        ->toThrow(BlockPositionConflictException::class);
});

// ── Test 3: reorder reloads blocks inside the transaction ─────────────────────
//
// Verifies that reorder() derives its parking offset from a fresh DB read inside
// the transaction, not from the stale pre-transaction snapshot.  The two-phase
// write (park → seat) must complete without a transient unique collision.
//
it('reorders blocks using a freshly loaded block set inside the transaction', function (): void {
    $post = concurrencyTestPost();
    $svc = concurrencyBlockService();

    $b0 = $svc->append($post, 'paragraph', ['content' => 'alpha']);
    $b1 = $svc->append($post, 'paragraph', ['content' => 'beta']);
    $b2 = $svc->append($post, 'paragraph', ['content' => 'gamma']);

    // Full reverse — the most collision-prone permutation for a 3-element set.
    $svc->reorder($post, [$b2->public_id, $b0->public_id, $b1->public_id]);

    $ordered = $post->fresh()->blocks;

    expect($ordered->pluck('public_id')->all())
        ->toBe([$b2->public_id, $b0->public_id, $b1->public_id])
        ->and($ordered->pluck('position')->all())->toBe([0, 1, 2]);
});

// ── Test 4: reorder detects block removal in the race window ──────────────────
//
// RACE WINDOW BEING TESTED
// ────────────────────────
// reorder() runs a pre-flight read (unlocked) then opens a transaction and does
// a second, locked read. A concurrent DELETE that commits AFTER the pre-flight
// but BEFORE the locked read changes the block set from under us. Without the
// in-tx re-validation, reorder() would silently skip the stale id (via ?->),
// leaving a position gap and corrupting the contiguous-positions invariant.
//
// DETERMINISTIC SIMULATION (single-threaded, SQLite)
// ───────────────────────────────────────────────────
// DB::listen fires synchronously AFTER each query executes. We register the
// listener just before calling reorder() so the FIRST content_blocks SELECT it
// sees is the pre-flight read. After that SELECT the listener deletes b0 via a
// raw DB::table call (auto-committed, outside any transaction). The in-memory
// $preflightBlocks PHP collection still holds b0, so the pre-flight check
// passes. When the transaction opens and the locked reload runs, it sees only
// b1 and b2 — and the in-tx re-validation detects the mismatch.
//
// RED  (pre-fix code): reorder() does NOT throw — it silently skips b0 and
//                      leaves a gap at position 1; toThrow() assertion fails.
// GREEN (post-fix): BlockPositionOutOfRangeException thrown → assertion passes.
//
it('throws BlockPositionOutOfRangeException when a block is removed in the reorder race window', function (): void {
    $post = concurrencyTestPost();
    $svc = concurrencyBlockService();

    $b0 = $svc->append($post, 'paragraph', ['content' => 'alpha']);
    $b1 = $svc->append($post, 'paragraph', ['content' => 'beta']);
    $b2 = $svc->append($post, 'paragraph', ['content' => 'gamma']);

    $table = config('blog-manager.tables.content_blocks', 'blog_content_blocks');
    $b0PublicId = $b0->public_id;
    $injected = false;

    // Listener is registered AFTER all appends, so the first content_blocks
    // SELECT it sees is the pre-flight read inside reorder().
    DB::listen(function ($query) use (&$injected, $table, $b0PublicId): void {
        if ($injected || ! str_contains($query->sql, $table)) {
            return;
        }
        // Set flag BEFORE the delete so the recursive listener call triggered
        // by the DELETE query returns immediately — no infinite recursion.
        $injected = true;
        DB::table($table)->where('public_id', $b0PublicId)->delete();
    });

    expect(fn () => $svc->reorder($post, [$b2->public_id, $b0->public_id, $b1->public_id]))
        ->toThrow(BlockPositionOutOfRangeException::class);
});
