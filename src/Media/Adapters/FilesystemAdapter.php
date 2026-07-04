<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Media\Adapters;

use Aristonis\BlogManager\Contracts\MediaStorageAdapter;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\MediaStorageFailedException;
use Aristonis\BlogManager\Media\MediaSource;
use Aristonis\BlogManager\Media\StoredMediaRef;
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Default storage adapter — persists binaries on a configurable Laravel
 * filesystem disk (`media.disk` / `media.path`). Filesystem-agnostic: point the
 * disk at local, S3, etc.
 */
final class FilesystemAdapter implements MediaStorageAdapter
{
    public function name(): string
    {
        return 'filesystem';
    }

    public function store(MediaSource $source, MediaKind $kind): StoredMediaRef
    {
        $disk = $this->disk();

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        // A MediaSource carries exactly one of a path XOR a stream (enforced by its
        // constructor). Persist from whichever is present; the stream branch never
        // closes the caller-owned handle (O-3).
        $path = $source->path !== null
            ? $this->storeFromPath($storage, $source->path)
            : $this->storeFromStream($storage, $source);

        if (! is_string($path)) {
            throw new MediaStorageFailedException('Failed to store the media file.', ['disk' => $disk]);
        }

        return new StoredMediaRef('filesystem', $disk, $path);
    }

    /**
     * Persist a binary already on a filesystem path, preserving today's hashed-name
     * placement under the configured media directory. The extension is derived from
     * the bytes by putFile()->guessExtension(), never from caller-supplied metadata.
     *
     * @return string|false the stored relative path, or false on failure
     */
    private function storeFromPath(\Illuminate\Filesystem\FilesystemAdapter $storage, string $path): string|false
    {
        return $storage->putFile($this->path(), new File($path));
    }

    /**
     * Persist a binary from a caller-supplied, open stream. The handle is read but
     * NEVER closed here — the caller owns and closes the resource it opened (O-3).
     *
     * The stored name carries NO caller-derived extension: honoring the caller's
     * originalFilename here (e.g. "shell.php") would create an executable path — a
     * web-shell vector, and asymmetric with the path branch which derives the
     * extension from the bytes. The human-readable name is preserved separately in
     * MediaItem.original_filename.
     *
     * @return string|false the stored relative path, or false on failure
     */
    private function storeFromStream(\Illuminate\Filesystem\FilesystemAdapter $storage, MediaSource $source): string|false
    {
        $target = $this->path().'/'.Str::random(40);

        if ($storage->writeStream($target, $source->stream()) === false) {
            return false;
        }

        return $target;
    }

    public function url(MediaItem $item, ?int $ttlMinutes = null): ?string
    {
        if ($item->disk === null || $item->path === null) {
            return null;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($item->disk);

        if ($ttlMinutes !== null) {
            try {
                return $storage->temporaryUrl($item->path, now()->addMinutes($ttlMinutes));
            } catch (Throwable) {
                // Drivers without signed-URL support (the local/public disk) reject
                // temporaryUrl() with a raw RuntimeException. Degrade to the plain
                // URL so a host on the default disk still gets a usable link (L2).
            }
        }

        try {
            return $storage->url($item->path);
        } catch (Throwable) {
            // Some drivers expose no public URL either; return null rather than
            // surface a raw driver exception to the caller (L2).
            return null;
        }
    }

    public function delete(MediaItem $item): void
    {
        if ($item->disk !== null && $item->path !== null) {
            Storage::disk($item->disk)->delete($item->path);
        }
    }

    private function disk(): string
    {
        $disk = config('blog-manager.media.disk', 'public');

        return is_string($disk) ? $disk : 'public';
    }

    private function path(): string
    {
        $path = config('blog-manager.media.path', 'blog-media');

        return is_string($path) ? $path : 'blog-media';
    }
}
