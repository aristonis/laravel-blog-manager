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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
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

    /**
     * Primary entry: persist a binary described by a {@see MediaSource} value object
     * (a path XOR a stream, plus caller-supplied metadata) and record a first-class
     * MediaItem. The order is guard -> validate -> store -> record; if the record
     * fails the stored binary is compensated so nothing is orphaned. The MIME is
     * trusted from the source (the package never re-sniffs the bytes — FR-84).
     *
     * SECURITY (host duty): a caller passing `size: 0` (unknown length) bypasses the
     * per-kind max-size cap (O-4). The host must enforce its own byte limit at the
     * transport layer BEFORE calling this method for untrusted, unknown-length content.
     */
    public function storeSource(MediaSource $source): MediaItem
    {
        // Guard FIRST so authorization cannot be bypassed via either entry point
        // (AC-64) — the store(UploadedFile) overload delegates here and does not
        // re-guard, keeping MEDIA_UPLOAD enforced exactly once.
        $this->guard->ensure(Abilities::MEDIA_UPLOAD);

        $kind = $this->kinds->resolve($source->mime);

        $this->validate($source, $kind);

        $adapter = $this->adapters->adapter();
        $ref = $adapter->store($source, $kind);

        try {
            $media = MediaItem::forceCreate([
                'kind' => $kind,
                'mime' => $source->mime,
                'size' => $source->size,
                'original_filename' => $source->originalFilename,
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
     * Convenience overload for the common HTTP path: build a {@see MediaSource} from
     * an uploaded file and delegate to {@see self::storeSource()}. MIME is the
     * server-detected type with the client-supplied type as fallback; the binary is
     * sourced from the upload's real path. Behavior-preserving (AC-61) — the guard,
     * validation, storage, and events all run in storeSource().
     */
    public function store(UploadedFile $file): MediaItem
    {
        return $this->storeSource(new MediaSource(
            path: $file->getRealPath() ?: $file->getPathname(),
            stream: null,
            mime: $file->getMimeType() ?: $file->getClientMimeType(),
            originalFilename: $file->getClientOriginalName(),
            size: (int) ($file->getSize() ?: 0),
        ));
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
            $locked = MediaItem::query()->whereKey($item->getKey())->lockForUpdate()->first();

            // Idempotency (FR-88): if the FOR UPDATE finds no row, a concurrent
            // already-committed delete won the race and removed it. Early-return
            // before the in-use check, the row delete, and the afterCommit binary
            // cleanup + MediaDeleted dispatch — so MediaDeleted fires EXACTLY ONCE
            // across the racers (the winner already fired it) instead of twice.
            if ($locked === null) {
                return;
            }

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
     * SECURITY: the returned MediaItem rows carry storage internals (disk / path /
     * adapter). A host must gate any EXTERNAL exposure of this result behind an
     * admin / MEDIA_DELETE authorization check — never hand it to unprivileged
     * callers.
     *
     * @return Collection<int, MediaItem>
     */
    public function orphaned(): Collection
    {
        return $this->orphanedQuery()->get();
    }

    /**
     * Streaming counterpart to {@see self::orphaned()} for large media tables: the
     * same orphan set, but yielded row-by-row via a database cursor instead of
     * materialising the whole result in memory at once. Prefer this over
     * {@see self::orphaned()} whenever the orphan set may be large (reclamation
     * sweeps). The underlying query is deferred until the returned LazyCollection is
     * enumerated.
     *
     * SECURITY: identical to {@see self::orphaned()} — the yielded MediaItem rows
     * carry storage internals (disk / path / adapter); gate any EXTERNAL exposure
     * behind an admin / MEDIA_DELETE authorization check.
     *
     * @return LazyCollection<int, MediaItem>
     */
    public function orphanedLazy(): LazyCollection
    {
        return $this->orphanedQuery()->cursor();
    }

    /**
     * Shared anti-join builder behind both orphan reads: media items for which NO
     * content block correlates via content_blocks.media_item_id. Uses a correlated
     * `whereNotExists` (portable across SQLite/MySQL/Postgres and index-driven on
     * content_blocks.media_item_id) rather than a `whereNotIn` subquery.
     *
     * A block with a NULL media_item_id needs no explicit guard: under the correlated
     * `whereColumn(content_blocks.media_item_id, blog_media_items.id)` a NULL yields
     * `NULL = id` → UNKNOWN, so the EXISTS row never matches and a NULL-referencing
     * block never counts as a reference (the former `whereNotNull` guard was dead —
     * FR-89 / AC-72).
     *
     * @return Builder<MediaItem>
     */
    private function orphanedQuery(): Builder
    {
        $blocks = (new ContentBlock)->getTable();
        $mediaTable = (new MediaItem)->getTable();

        return MediaItem::query()
            ->whereNotExists(function (QueryBuilder $query) use ($blocks, $mediaTable): void {
                $query->select(DB::raw(1))
                    ->from($blocks)
                    ->whereColumn("{$blocks}.media_item_id", "{$mediaTable}.id");
            });
    }

    private function validate(MediaSource $source, MediaKind $kind): void
    {
        $mime = $source->mime;

        /** @var array<int, string> $allowed */
        $allowed = (array) config("blog-manager.media.allowed_mime.{$kind->value}", []);
        if (! in_array($mime, $allowed, true)) {
            // $mime is caller-supplied (for an upload it can fall back to the
            // attacker-controlled client MIME), so interpolating it raw enables
            // CRLF / log injection. Strip control chars for the MESSAGE only; the
            // raw value stays in context (M8, FR-85). This sanitize now lives in the
            // shared pipeline, so it applies to every source type — not just uploads.
            $safeMime = preg_replace('/[\p{C}]/u', '', $mime) ?? '';

            throw new MediaValidationException(
                "MIME type [{$safeMime}] is not allowed for {$kind->value}.",
                ['mime' => $mime, 'kind' => $kind->value],
            );
        }

        $max = (int) config("blog-manager.media.max_size.{$kind->value}", 0);
        // size:0 means "unknown length" (e.g. an unbounded stream); with $size = 0
        // this guard is already false, so an unknown-length source deliberately
        // skips the cap (O-4). Documented behavior, not a silent bypass.
        $size = $source->size;
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
