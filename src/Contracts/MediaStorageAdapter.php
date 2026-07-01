<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Contracts;

use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Media\StoredMediaRef;
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Http\UploadedFile;

/**
 * The storage port. Handles the binary only — never the database record (that is
 * the MediaManager's job). Register a new adapter with the MediaAdapterManager to
 * plug in an external provider (e.g. spatie/laravel-medialibrary) — no core edit.
 */
interface MediaStorageAdapter
{
    /** The driver key this adapter is registered under. */
    public function name(): string;

    /** Persist the uploaded binary and return a reference to it. */
    public function store(UploadedFile $file, MediaKind $kind): StoredMediaRef;

    /** Resolve a (optionally temporary) URL for the item, or null if unavailable. */
    public function url(MediaItem $item, ?int $ttlMinutes = null): ?string;

    /** Remove the stored binary. */
    public function delete(MediaItem $item): void;
}
