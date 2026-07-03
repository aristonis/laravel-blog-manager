<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Media\Adapters;

use Aristonis\BlogManager\Contracts\MediaStorageAdapter;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\MediaStorageFailedException;
use Aristonis\BlogManager\Media\StoredMediaRef;
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function store(UploadedFile $file, MediaKind $kind): StoredMediaRef
    {
        $disk = $this->disk();

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);
        $path = $storage->putFile($this->path(), $file);

        if (! is_string($path)) {
            throw new MediaStorageFailedException('Failed to store the media file.', ['disk' => $disk]);
        }

        return new StoredMediaRef('filesystem', $disk, $path);
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
