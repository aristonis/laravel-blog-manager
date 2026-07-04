<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Media;

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Authorization\ServiceAuthorizer;
use Aristonis\BlogManager\Contracts\MediaStorageAdapter;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Events\MediaDeleted;
use Aristonis\BlogManager\Events\MediaStored;
use Aristonis\BlogManager\Exceptions\MediaInUseException;
use Aristonis\BlogManager\Exceptions\MediaStorageFailedException;
use Aristonis\BlogManager\Exceptions\MediaValidationException;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the media item lifecycle: validate at the boundary, delegate the binary to
 * the active adapter, and record a first-class MediaItem. Reference-safe deletion.
 * The order is validate -> store -> record; if the record fails the stored binary
 * is compensated so nothing is orphaned.
 */
final class MediaManager
{
    public function __construct(
        private readonly MediaAdapterManager $adapters,
        private readonly MediaKindResolver $kinds,
        private readonly ServiceAuthorizer $guard,
    ) {}

    public function store(UploadedFile $file): MediaItem
    {
        $this->guard->ensure(Abilities::MEDIA_UPLOAD);
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $kind = $this->kinds->resolve($mime);

        $this->validate($file, $kind, $mime);

        $adapter = $this->adapters->adapter();
        $ref = $adapter->store($file, $kind);

        try {
            $media = MediaItem::forceCreate([
                'kind' => $kind,
                'mime' => $mime,
                'size' => (int) ($file->getSize() ?: 0),
                'original_filename' => $file->getClientOriginalName(),
                'adapter' => $ref->adapter,
                'disk' => $ref->disk,
                'path' => $ref->path,
                'meta' => $ref->meta === [] ? null : $ref->meta,
            ]);

            event(new MediaStored($media));

            return $media;
        } catch (Throwable $e) {
            $this->compensate($adapter, $ref);

            throw new MediaStorageFailedException('Failed to record the stored media item.', [], $e);
        }
    }

    /**
     * Delete a media item and its binary. Refused (fail-loud) while any block
     * still references it.
     */
    public function delete(MediaItem $item): void
    {
        $this->guard->ensure(Abilities::MEDIA_DELETE, $item);

        $adapter = $this->adapters->adapter($item->adapter);

        // Row-first, binary-last. The DB row is removed inside the transaction;
        // the binary is removed only at the TRUE outermost commit — so a rollback
        // (including a host-owned outer transaction rolling back) leaves the file
        // intact and never orphans a row against a deleted binary (H2 defect #1).
        DB::transaction(function () use ($item, $adapter): void {
            // Take a FOR UPDATE lock on THIS media row, then re-check in-use under
            // that lock so check-and-delete is atomic against a concurrent append().
            // The serialisation is the FK, not the lock alone: append()'s INSERT
            // into content_blocks takes a KEY-SHARE lock on the referenced
            // blog_media_items row (FK, nullOnDelete), which conflicts with the
            // FOR UPDATE taken here — so a committed-or-in-flight append cannot
            // interleave and silently lose its media_item_id via nullOnDelete
            // (H2 defect #2). Do not drop this lock: it is what the FK contends on.
            // NOTE: a fully-concurrent *uncommitted* append remains a narrow,
            // inherent window of the nullOnDelete design and is not solved here.
            MediaItem::query()->whereKey($item->getKey())->lockForUpdate()->first();

            if ($item->blocks()->exists()) {
                throw new MediaInUseException('Media item is still referenced by a block.', ['media' => $item->public_id]);
            }

            $item->delete();

            // Defer binary cleanup AND the MediaDeleted signal to the TRUE outermost
            // commit. afterCommit() attaches to the current transaction record and
            // only fires at transaction level 0 — so when a host wraps delete() in
            // its own transaction (inner DB::transaction is a mere SAVEPOINT), the
            // callback still waits for the host's real commit and is DISCARDED on a
            // host rollback. That closes the nested-transaction reopening of H2.
            //
            // Ordering inside the callback is binary-first, then event: the in-memory
            // $item still carries adapter/disk/path (MediaItem has no SoftDeletes),
            // so the adapter can locate the binary even though the row is gone.
            DB::afterCommit(function () use ($adapter, $item): void {
                try {
                    $adapter->delete($item);
                } catch (Throwable $e) {
                    // Post-commit: the record is already authoritatively deleted, so a
                    // raw driver failure here must NOT surface to the caller as a total
                    // failure. report() routes it to the host's exception handler. The
                    // orphaned binary is reclaimable via the future orphan-media query
                    // (backlog M7).
                    report($e);
                }

                // The record IS deleted, so signal regardless of the binary outcome.
                // We are at transaction level 0 here, so this ShouldDispatchAfterCommit
                // event dispatches immediately — after the binary cleanup above.
                event(new MediaDeleted($item));
            });
        });
    }

    public function url(MediaItem $item, ?int $ttlMinutes = null): ?string
    {
        return $this->adapters->adapter($item->adapter)->url($item, $ttlMinutes);
    }

    /**
     * Media items not referenced by any LIVE content block (through
     * content_blocks.media_item_id). A read-only reclamation seam — it never
     * deletes. Revision-snapshot JSON references deliberately do NOT count as
     * live references, so a media item kept alive only by an old snapshot is
     * still reported as orphaned. Unguarded, consistent with the package's
     * other read paths (no authorization on reads).
     *
     * WARNING: returns an unbounded eager Collection — the full orphan set is
     * materialised in memory in one shot, so this is NOT safe to call against a
     * very large media table. A lazy/chunked reclamation variant is future work.
     *
     * @return Collection<int, MediaItem>
     */
    public function orphaned(): Collection
    {
        $blocks = (new ContentBlock)->getTable();

        return MediaItem::query()
            ->whereNotIn('id', function (QueryBuilder $query) use ($blocks): void {
                $query->select('media_item_id')
                    ->from($blocks)
                    ->whereNotNull('media_item_id');
            })
            ->get();
    }

    private function validate(UploadedFile $file, MediaKind $kind, string $mime): void
    {
        /** @var array<int, string> $allowed */
        $allowed = (array) config("blog-manager.media.allowed_mime.{$kind->value}", []);
        if (! in_array($mime, $allowed, true)) {
            // $mime can fall back to the attacker-controlled client MIME, so
            // interpolating it raw enables CRLF / log injection. Strip control
            // chars for the MESSAGE only; the raw value stays in context (M8).
            $safeMime = preg_replace('/[\p{C}]/u', '', $mime) ?? '';

            throw new MediaValidationException(
                "MIME type [{$safeMime}] is not allowed for {$kind->value}.",
                ['mime' => $mime, 'kind' => $kind->value],
            );
        }

        $max = (int) config("blog-manager.media.max_size.{$kind->value}", 0);
        $size = (int) ($file->getSize() ?: 0);
        if ($max > 0 && $size > $max) {
            throw new MediaValidationException(
                "File exceeds the maximum size for {$kind->value}.",
                ['size' => $size, 'max' => $max],
            );
        }
    }

    private function compensate(MediaStorageAdapter $adapter, StoredMediaRef $ref): void
    {
        try {
            $adapter->delete((new MediaItem)->forceFill([
                'adapter' => $ref->adapter,
                'disk' => $ref->disk,
                'path' => $ref->path,
            ]));
        } catch (Throwable) {
            // best-effort cleanup; swallow so the original failure surfaces
        }
    }
}
