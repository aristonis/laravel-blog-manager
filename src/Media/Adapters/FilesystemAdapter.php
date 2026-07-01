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

        return $ttlMinutes !== null
            ? $storage->temporaryUrl($item->path, now()->addMinutes($ttlMinutes))
            : $storage->url($item->path);
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
