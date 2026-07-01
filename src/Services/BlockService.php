<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

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
    public function __construct(private readonly BlockTypeRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function append(Post $post, string $type, array $data = [], ?MediaItem $media = null): ContentBlock
    {
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
            $block = ContentBlock::create([
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
        $normalized = $this->registry->get($block->type)->validate($data);

        return DB::transaction(function () use ($block, $normalized): ContentBlock {
            $block->update(['data' => $normalized === [] ? null : $normalized]);

            event(new BlockUpdated($block));

            return $block->refresh();
        });
    }

    public function remove(ContentBlock $block): void
    {
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
            foreach ($orderedPublicIds as $position => $publicId) {
                $block = $blocks->get($publicId);
                if ($block instanceof ContentBlock && $block->position !== $position) {
                    $block->update(['position' => $position]);
                }
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
