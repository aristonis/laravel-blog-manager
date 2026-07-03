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
use Aristonis\BlogManager\Exceptions\BlockPositionOutOfRangeException;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Block lifecycle. Owns the position invariant (unique + contiguous 0..n-1) and
 * enforces media-kind matching via the block type. Transactions + after-commit events.
 */
final class BlockService
{
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

        return DB::transaction(function () use ($post, $type, $normalized, $media): ContentBlock {
            $block = ContentBlock::forceCreate([
                'post_id' => $post->id,
                'type' => $type,
                'position' => (int) $post->blocks()->count(),
                'data' => $normalized === [] ? null : $normalized,
                'media_item_id' => $media?->id,
            ]);

            event(new BlockAppended($block));

            return $block;
        });
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
        $blocks = $post->blocks()->get()->keyBy('public_id');
        $currentIds = $blocks->keys()->all();

        if (count($orderedPublicIds) !== count($currentIds)
            || array_diff($currentIds, $orderedPublicIds) !== []
            || array_diff($orderedPublicIds, $currentIds) !== []
        ) {
            throw new BlockPositionOutOfRangeException(
                'The reorder list must contain exactly the post\'s blocks.',
                ['post' => $post->public_id],
            );
        }

        DB::transaction(function () use ($post, $orderedPublicIds, $blocks): void {
            // Two-phase write so the DB-level unique(post_id, position) never sees a
            // transient collision: first park every block above the final range, then
            // seat each at its final contiguous position. The two ranges are disjoint,
            // so no intermediate step ever duplicates a (post_id, position) pair.
            $offset = count($orderedPublicIds);

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
