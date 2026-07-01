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
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Http\UploadedFile;
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
            $media = MediaItem::create([
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

        if ($item->blocks()->exists()) {
            throw new MediaInUseException('Media item is still referenced by a block.', ['media' => $item->public_id]);
        }

        $adapter = $this->adapters->adapter($item->adapter);

        DB::transaction(function () use ($item, $adapter): void {
            $adapter->delete($item);
            $item->delete();

            event(new MediaDeleted($item));
        });
    }

    public function url(MediaItem $item, ?int $ttlMinutes = null): ?string
    {
        return $this->adapters->adapter($item->adapter)->url($item, $ttlMinutes);
    }

    private function validate(UploadedFile $file, MediaKind $kind, string $mime): void
    {
        /** @var array<int, string> $allowed */
        $allowed = (array) config("blog-manager.media.allowed_mime.{$kind->value}", []);
        if (! in_array($mime, $allowed, true)) {
            throw new MediaValidationException(
                "MIME type [{$mime}] is not allowed for {$kind->value}.",
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
            $adapter->delete(new MediaItem([
                'adapter' => $ref->adapter,
                'disk' => $ref->disk,
                'path' => $ref->path,
            ]));
        } catch (Throwable) {
            // best-effort cleanup; swallow so the original failure surfaces
        }
    }
}
