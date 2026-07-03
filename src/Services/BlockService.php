<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Authorization\ServiceAuthorizer;
use Aristonis\BlogManager\Blocks\BlockTypeRegistry;
use Aristonis\BlogManager\Events\BlockAppended;
use Aristonis\BlogManager\Events\BlockRemoved;
use Aristonis\BlogManager\Events\BlocksReordered;
use Aristonis\BlogManager\Events\BlockUpdated;
use Aristonis\BlogManager\Exceptions\BlockKindMismatchException;
use Aristonis\BlogManager\Exceptions\BlockPositionConflictException;
use Aristonis\BlogManager\Exceptions\BlockPositionOutOfRangeException;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Block lifecycle. Owns the position invariant (unique + contiguous 0..n-1) and
 * enforces media-kind matching via the block type. Transactions + after-commit events.
 */
final class BlockService
{
    /**
     * Maximum number of times append() will retry after a (post_id, position)
     * unique-constraint collision before giving up and throwing
     * {@see BlockPositionConflictException}.
     */
    private const MAX_APPEND_RETRIES = 3;

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly ServiceAuthorizer $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function append(Post $post, string $type, array $data = [], ?MediaItem $media = null): ContentBlock
    {
        $this->guard->ensure(Abilities::BLOCK_MANAGE, $post);
        $blockType = $this->registry->get($type);
        $requiredKind = $blockType->requiresMediaKind();

        if ($requiredKind !== null) {
            if ($media === null || $media->kind !== $requiredKind) {
                throw new BlockKindMismatchException(
                    "Block [{$type}] requires media of kind [{$requiredKind->value}].",
                    ['type' => $type, 'required' => $requiredKind->value],
                );
            }
        } else {
            $media = null;
        }

        $normalized = $blockType->validate($data);

        // Position is computed INSIDE the transaction so each retry gets a fresh,
        // consistent count. Locking the parent post row (not the aggregate count)
        // serialises concurrent appends per post: Postgres rejects FOR UPDATE on
        // an aggregate (count() ... FOR UPDATE), so we lock the owning Post row
        // instead — valid on SQLite / MySQL / Postgres alike.
        //
        // The retry loop (bounded by MAX_APPEND_RETRIES) transparently resolves
        // the race where two concurrent appends both read count() = N and both
        // attempt position = N — the loser catches UniqueConstraintViolationException,
        // waits for the winner's transaction to commit, re-reads the new count, and
        // inserts at N+1.
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_APPEND_RETRIES; $attempt++) {
            try {
                return DB::transaction(function () use ($post, $type, $normalized, $media): ContentBlock {
                    // Lock the parent post row to serialise concurrent appends.
                    // A row lock on a scalar SELECT is valid on SQLite/MySQL/Postgres;
                    // a FOR UPDATE on an aggregate (count()) is rejected by Postgres.
                    Post::query()->whereKey($post->id)->lockForUpdate()->first();
                    $position = (int) $post->blocks()->count();

                    $block = ContentBlock::forceCreate([
                        'post_id' => $post->id,
                        'type' => $type,
                        'position' => $position,
                        'data' => $normalized === [] ? null : $normalized,
                        'media_item_id' => $media?->id,
                    ]);

                    event(new BlockAppended($block));

                    return $block;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Unique-constraint violation on (post_id, position): retry.
                // FK / NOT-NULL / other DB errors are NOT caught here and propagate
                // immediately — they are not retriable and must not be mislabelled
                // as a position conflict.
                $lastException = $e;
            }
        }

        // All attempts exhausted. Surface as a typed package exception so the
        // caller is never exposed to a raw Illuminate\Database\QueryException.
        throw new BlockPositionConflictException(
            "Could not append block to post [{$post->public_id}]: unique position"
            .' conflict after '.self::MAX_APPEND_RETRIES.' attempts.',
            ['post' => $post->public_id, 'type' => $type],
            $lastException,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ContentBlock $block, array $data): ContentBlock
    {
        $this->guard->ensure(Abilities::BLOCK_MANAGE, $block);
        $normalized = $this->registry->get($block->type)->validate($data);

        return DB::transaction(function () use ($block, $normalized): ContentBlock {
            $block->update(['data' => $normalized === [] ? null : $normalized]);

            event(new BlockUpdated($block));

            return $block->refresh();
        });
    }

    public function remove(ContentBlock $block): void
    {
        $this->guard->ensure(Abilities::BLOCK_MANAGE, $block);

        DB::transaction(function () use ($block): void {
            $post = $block->post;
            $block->delete();
            $this->resequence($post);

            event(new BlockRemoved($block));
        });
    }

    /**
     * @param  list<string>  $orderedPublicIds  the post's block public ids in the new order
     */
    public function reorder(Post $post, array $orderedPublicIds): void
    {
        $this->guard->ensure(Abilities::BLOCK_MANAGE, $post);

        // Pre-flight validation using an optimistic (unlocked) snapshot.
        // Catches the common case where a concurrent mutation has already committed.
        // The locked re-read + in-tx re-validation inside the transaction handles
        // the narrower window where a mutation commits after this read.
        $preflightBlocks = $post->blocks()->get()->keyBy('public_id');
        $currentIds = $preflightBlocks->keys()->all();

        if (count($orderedPublicIds) !== count($currentIds)
            || array_diff($currentIds, $orderedPublicIds) !== []
            || array_diff($orderedPublicIds, $currentIds) !== []
        ) {
            throw new BlockPositionOutOfRangeException(
                'The reorder list must contain exactly the post\'s blocks.',
                ['post' => $post->public_id],
            );
        }

        DB::transaction(function () use ($post, $orderedPublicIds): void {
            // Reload the block set with a row lock to close the race window between
            // the pre-flight snapshot and the write phase. On InnoDB/Postgres,
            // lockForUpdate() prevents concurrent writers from proceeding until this
            // transaction commits. On SQLite (tests) it is a no-op; the in-tx
            // re-validation below acts as the safety net.
            $blocks = $post->blocks()->lockForUpdate()->get()->keyBy('public_id');

            // In-tx re-validation: a concurrent remove that committed AFTER the
            // pre-flight check but BEFORE this lock would cause the ?-> null-safe
            // calls in the write loop to silently skip the stale id, leaving a
            // position gap. Detect the mismatch here and throw loudly instead.
            $lockedIds = $blocks->keys()->all();

            if (count($orderedPublicIds) !== count($lockedIds)
                || array_diff($lockedIds, $orderedPublicIds) !== []
                || array_diff($orderedPublicIds, $lockedIds) !== []
            ) {
                throw new BlockPositionOutOfRangeException(
                    'The reorder list must contain exactly the post\'s blocks.',
                    ['post' => $post->public_id],
                );
            }

            // Parking offset from the high-water mark of the LOCKED set, not
            // from count(). If positions are non-contiguous (prior bug), count()
            // could equal an existing position and collide; max(position)+1 is
            // always strictly above every existing position.
            $offset = ($blocks->max('position') ?? -1) + 1;

            foreach ($orderedPublicIds as $finalPosition => $publicId) {
                $blocks->get($publicId)?->update(['position' => $finalPosition + $offset]);
            }

            foreach ($orderedPublicIds as $finalPosition => $publicId) {
                $blocks->get($publicId)?->update(['position' => $finalPosition]);
            }

            event(new BlocksReordered($post));
        });
    }

    private function resequence(Post $post): void
    {
        $post->blocks()->orderBy('position')->get()->values()
            ->each(function (ContentBlock $block, int $index): void {
                if ($block->position !== $index) {
                    $block->update(['position' => $index]);
                }
            });
    }
}
