<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Authorization\ServiceAuthorizer;
use Aristonis\BlogManager\Blocks\BlockTypeRegistry;
use Aristonis\BlogManager\Enums\PostStatus;
use Aristonis\BlogManager\Events\PostRestored;
use Aristonis\BlogManager\Events\PostRevisionCreated;
use Aristonis\BlogManager\Exceptions\BlockKindMismatchException;
use Aristonis\BlogManager\Exceptions\RevisionMediaMissingException;
use Aristonis\BlogManager\Exceptions\RevisionNotFoundException;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;
use Aristonis\BlogManager\Support\SlugGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Post revision history: immutable snapshots + non-destructive restore. Owns
 * transactions and dispatches domain events after commit. History is
 * append-only — a snapshot is written once and never mutated (D26).
 */
final class RevisionService
{
    public function __construct(
        private readonly ServiceAuthorizer $guard,
        private readonly BlockTypeRegistry $registry,
        private readonly SlugGenerator $slugs,
    ) {}

    /**
     * Capture a full immutable snapshot of the post — its attributes plus the
     * ordered block tree (media referenced by id, not copied). Dispatches
     * PostRevisionCreated after commit and prunes to revisions.keep.
     */
    public function snapshot(Post $post, ?string $label = null, mixed $createdBy = null): PostRevision
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);

        return DB::transaction(function () use ($post, $label, $createdBy): PostRevision {
            $revision = $this->record($post, $label, $createdBy);

            $this->prune($post);

            return $revision;
        });
    }

    /**
     * Write one snapshot row and fire PostRevisionCreated. No retention pruning —
     * the caller decides when to prune. Restore records via this (never through
     * snapshot()) so a tight retention cap can't evict the revision being restored.
     */
    private function record(Post $post, ?string $label, mixed $createdBy): PostRevision
    {
        $revision = PostRevision::forceCreate([
            'post_id' => $post->id,
            'snapshot' => $this->serialize($post),
            'label' => $label,
            'created_by' => $createdBy,
        ]);

        event(new PostRevisionCreated($revision));

        return $revision;
    }

    /**
     * A post's revisions, newest first — all as a collection, or a paginator
     * when $perPage is given.
     *
     * @return Collection<int, PostRevision>|LengthAwarePaginator<int, PostRevision>
     */
    public function for(Post $post, ?int $perPage = null): Collection|LengthAwarePaginator
    {
        return $perPage === null
            ? $post->revisions()->get()
            : $post->revisions()->paginate($perPage);
    }

    /**
     * Fetch one of a post's revisions by public id, scoped to the post so a
     * foreign or absent id is a not-found (never leaks another post's history).
     */
    public function get(Post $post, string $revisionPublicId): PostRevision
    {
        $revision = $post->revisions()->where('public_id', $revisionPublicId)->first();

        if ($revision === null) {
            throw new RevisionNotFoundException(
                "Revision [{$revisionPublicId}] was not found for this post.",
                ['post' => $post->public_id, 'revision' => $revisionPublicId],
            );
        }

        return $revision;
    }

    /**
     * Non-destructively restore the post to a revision. The pre-restore state is
     * captured first (never lost), then attributes + block tree are rebuilt from
     * the snapshot. Media is resolved by reference: a missing item aborts with
     * RevisionMediaMissingException by default, or is dropped under
     * revisions.on_missing_media=lenient; a $mediaRemap (old media public id =>
     * new media public id) repairs gaps. Content-only unless $restorePublishState.
     * Dispatches PostRestored after commit.
     *
     * @param  array<string, string>  $mediaRemap
     */
    public function restore(Post $post, PostRevision $revision, bool $restorePublishState = false, array $mediaRemap = []): Post
    {
        // Restore rewrites both post attributes and the block tree, so it requires
        // both abilities — a caller with POST_UPDATE but not BLOCK_MANAGE must not
        // rebuild blocks through this path.
        $this->guard->ensure(Abilities::POST_UPDATE, $post);
        $this->guard->ensure(Abilities::BLOCK_MANAGE, $post);

        if ($revision->post_id !== $post->id) {
            throw new RevisionNotFoundException(
                'The revision does not belong to this post.',
                ['post' => $post->public_id, 'revision' => $revision->public_id],
            );
        }

        $snapshot = $revision->snapshot;
        $resolution = $this->resolveBlocks((array) ($snapshot['blocks'] ?? []), $mediaRemap);

        if ($resolution['missing'] !== [] && config('blog-manager.revisions.on_missing_media') !== 'lenient') {
            throw new RevisionMediaMissingException(
                'Restore blocked: the revision references media that no longer exists.',
                ['post' => $post->public_id, 'revision' => $revision->public_id, 'missing' => $resolution['missing']],
            );
        }

        return DB::transaction(function () use ($post, $snapshot, $resolution, $restorePublishState, $revision): Post {
            // Non-destructive: preserve the current state before overwriting it.
            // record() (not snapshot()) so retention pruning can't evict the source
            // revision mid-restore; retention re-applies on the next capture.
            $this->record($post, 'auto: before restore', null);

            $slugChanged = $this->applyAttributes($post, (array) ($snapshot['post'] ?? []), $restorePublishState);
            $this->rebuildBlocks($post, $resolution['blocks']);

            // Repaired (media remapped or dropped) => the restored state differs
            // from the source revision, so record it as a fresh revision.
            if ($resolution['repaired']) {
                $this->record($post, 'restored (repaired)', null);
            }

            $fresh = $post->refresh();
            event(new PostRestored($fresh, $revision, $slugChanged));

            return $fresh;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Post $post): array
    {
        $post->loadMissing('blocks.mediaItem');

        return [
            'post' => [
                'title' => $post->title,
                'slug' => $post->slug,
                'author_id' => $post->author_id,
                'status' => ($post->status ?? PostStatus::Draft)->value,
                'published_at' => $post->published_at?->toIso8601String(),
            ],
            'blocks' => $post->blocks->map(fn (ContentBlock $block): array => [
                // Capture the block's public_id so a restore can re-seat the SAME
                // identity instead of minting a fresh ULID (M3a) — external
                // deep-links / cache keys / analytics keyed on it stay valid.
                'public_id' => $block->public_id,
                'type' => $block->type,
                'position' => $block->position,
                'data' => $block->data,
                'media' => $block->mediaItem === null ? null : [
                    'public_id' => $block->mediaItem->public_id,
                    'original_filename' => $block->mediaItem->original_filename,
                ],
            ])->all(),
        ];
    }

    /**
     * Resolve each snapshot block's media reference to a live media id, applying
     * $mediaRemap; collect missing references; re-sequence survivors to 0..n-1.
     *
     * @param  array<int, mixed>  $blocks
     * @param  array<string, string>  $mediaRemap
     * @return array{blocks: list<array<string, mixed>>, missing: list<array<string, mixed>>, repaired: bool}
     */
    private function resolveBlocks(array $blocks, array $mediaRemap): array
    {
        $mediaByPublicId = $this->fetchMedia($blocks, $mediaRemap);
        $resolved = [];
        $missing = [];
        $repaired = false;
        $position = 0;

        foreach ($blocks as $block) {
            $block = (array) $block;

            $media = $block['media'] ?? null;
            $mediaId = null;

            if (is_array($media)) {
                $oldPublicId = $media['public_id'] ?? null;
                $lookupPublicId = is_string($oldPublicId) && array_key_exists($oldPublicId, $mediaRemap)
                    ? $mediaRemap[$oldPublicId]
                    : $oldPublicId;

                if (is_string($oldPublicId) && $lookupPublicId !== $oldPublicId) {
                    $repaired = true;
                }

                $item = is_string($lookupPublicId) ? ($mediaByPublicId[$lookupPublicId] ?? null) : null;

                if ($item === null) {
                    $missing[] = [
                        'position' => $block['position'] ?? null,
                        'type' => $block['type'] ?? null,
                        'original_filename' => $media['original_filename'] ?? null,
                        'media_public_id' => $oldPublicId,
                    ];

                    continue; // dropped under lenient; strict throws before use
                }

                $this->assertKindMatches((string) ($block['type'] ?? ''), $item);
                $mediaId = $item->id;
            }

            // M3b — registry MEMBERSHIP check, now guarding SURVIVORS only (blocks
            // that will actually be rebuilt: text blocks + media blocks whose media
            // resolved). get() throws BlockTypeNotRegisteredException on an unknown
            // type, so a type unregistered since the snapshot fails loud at restore
            // time instead of blowing up later at render. Placing it AFTER the
            // media-missing `continue` means a block that is both unregistered AND
            // un-restorable (missing media) is dropped under lenient rather than
            // hard-failing the restore. This is a membership check only: restore
            // deliberately does NOT re-run BlockType::validate() on snapshot data,
            // so tightening a type's rules later can't break restore of old revisions.
            $this->registry->get((string) ($block['type'] ?? ''));

            $resolved[] = [
                // Carry the snapshot's block public_id through so rebuildBlocks can
                // re-seat it (M3a); null/absent for pre-SG-4 snapshots.
                'public_id' => $block['public_id'] ?? null,
                'type' => $block['type'] ?? null,
                'position' => $position++,
                'data' => $block['data'] ?? null,
                'media_item_id' => $mediaId,
            ];
        }

        return ['blocks' => $resolved, 'missing' => $missing, 'repaired' => $repaired || $missing !== []];
    }

    /**
     * Resolve every media reference in the snapshot (after remap) in a single
     * query, keyed by public id — avoids an N+1 across media blocks.
     *
     * @param  array<int, mixed>  $blocks
     * @param  array<string, string>  $mediaRemap
     * @return array<string, MediaItem>
     */
    private function fetchMedia(array $blocks, array $mediaRemap): array
    {
        $publicIds = [];

        foreach ($blocks as $block) {
            $media = ((array) $block)['media'] ?? null;
            $publicId = is_array($media) ? ($media['public_id'] ?? null) : null;

            if (is_string($publicId)) {
                $publicIds[] = array_key_exists($publicId, $mediaRemap) ? $mediaRemap[$publicId] : $publicId;
            }
        }

        if ($publicIds === []) {
            return [];
        }

        return MediaItem::query()
            ->whereIn('public_id', array_values(array_unique($publicIds)))
            ->get()
            ->keyBy('public_id')
            ->all();
    }

    private function assertKindMatches(string $type, MediaItem $item): void
    {
        $requiredKind = $this->registry->get($type)->requiresMediaKind();

        if ($requiredKind !== null && $item->kind !== $requiredKind) {
            throw new BlockKindMismatchException(
                "Restored block [{$type}] requires media of kind [{$requiredKind->value}].",
                ['type' => $type, 'required' => $requiredKind->value],
            );
        }
    }

    /**
     * Apply the snapshot's post attributes. Returns whether the slug had to diverge
     * from the snapshot's intended value (M4): the restore keeps its best-effort
     * uniquify behaviour, but reports it so the divergence is observable rather than
     * silent.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyAttributes(Post $post, array $attributes, bool $restorePublishState): bool
    {
        if (is_string($attributes['title'] ?? null)) {
            $post->title = $attributes['title'];
        }

        // M4 — the snapshot's intended (normalized) slug. unique() may append a
        // -2/-3 suffix if another post now holds it; capture whether that happened so
        // the otherwise-silent re-uniquification can be surfaced on PostRestored.
        $intendedSlug = Str::slug((string) ($attributes['slug'] ?? $post->slug));
        $post->slug = $this->slugs->unique(Post::class, $intendedSlug, $post->id, 'post');
        // An empty intended slug (degenerate/legacy snapshot) isn't a collision: the
        // empty→'post' fallback substitution by unique() is expected, not a divergence
        // worth surfacing — only a non-empty intended slug that got re-uniquified counts.
        $slugChanged = $intendedSlug !== '' && $post->slug !== $intendedSlug;

        // author_id is intentionally NOT restored: it is ownership-sensitive, and a
        // restore should not silently reassign a post's author. A host that wants to
        // revert the author does it explicitly via PostService::update().

        if ($restorePublishState) {
            $post->status = PostStatus::from((string) ($attributes['status'] ?? PostStatus::Draft->value));
            $post->published_at = $attributes['published_at'] ?? null;
        }

        $post->save();

        return $slugChanged;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function rebuildBlocks(Post $post, array $blocks): void
    {
        $post->blocks()->delete();

        foreach ($blocks as $block) {
            $attributes = [
                'post_id' => $post->id,
                'type' => $block['type'],
                'position' => $block['position'],
                'data' => $block['data'] ?? null,
                'media_item_id' => $block['media_item_id'] ?? null,
            ];

            // M3a — re-seat the snapshot's block public_id so restore preserves block
            // identity. HasPublicId's `creating` hook only mints when public_id is
            // empty, so a provided id is kept; the old rows were deleted just above in
            // this same tx, so there is no unique(public_id) collision. Pre-SG-4
            // snapshots carry no public_id — omit the key and let HasPublicId mint.
            $publicId = $block['public_id'] ?? null;
            if (is_string($publicId) && $publicId !== '') {
                $attributes['public_id'] = $publicId;
            }

            ContentBlock::forceCreate($attributes);
        }

        // Drop the now-stale cached relation so a later serialize()/refresh sees
        // the rebuilt blocks, not the pre-restore collection loaded earlier.
        $post->unsetRelation('blocks');
    }

    private function prune(Post $post): void
    {
        $keep = config('blog-manager.revisions.keep');

        if (! is_int($keep) || $keep <= 0) {
            return;
        }

        // Keep the newest N; delete this post's remaining revisions at the query
        // level (no in-memory slice).
        $keepIds = $post->revisions()->orderByDesc('id')->limit($keep)->pluck('id');

        $post->revisions()->whereNotIn('id', $keepIds)->delete();
    }
}
