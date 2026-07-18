---
name: blog-manager-add-media-adapter
description: Add a custom media storage backend (S3 with custom logic, a CDN, spatie/laravel-medialibrary) to aristonis/laravel-blog-manager by implementing the MediaStorageAdapter contract and registering a driver on MediaAdapterManager, without editing the core. Use when changing where or how media binaries are stored.
---

# Skill — add a media storage adapter

Goal: store media through a different backend (e.g. `spatie/laravel-medialibrary`, a CDN, S3 with custom logic)
**without editing the core** (OCP).

## Steps
1. **Implement** `Aristonis\BlogManager\Contracts\MediaStorageAdapter`:
   - `name()` — the driver key.
   - `store(MediaSource $source, MediaKind $kind): StoredMediaRef` — persist the **binary only** and return a
     `StoredMediaRef($adapter, $disk, $path, $meta)`. Never write the DB record — the `MediaManager` does that.
     The `MediaSource` (`Aristonis\BlogManager\Media\MediaSource`) carries **exactly one** of a filesystem
     `$source->path` **or** an open `$source->stream` (plus `$source->mime` / `$source->originalFilename` /
     `$source->size`) — branch on whichever is set. **Never close a supplied `$source->stream`**: the caller owns
     the resource it opened (rewind/close is the caller's job). A `$source->size` of `0` means **unknown length**.
   - `url(MediaItem $item, ?int $ttlMinutes = null): ?string` — resolve a (optionally temporary) URL.
   - `delete(MediaItem $item): void` — remove the binary.

2. **Register** the driver from your app's provider `boot()`:
   ```php
   use Aristonis\BlogManager\Media\MediaAdapterManager;

   $this->app->make(MediaAdapterManager::class)
       ->extend('medialibrary', fn () => new MediaLibraryAdapter);
   ```

3. **Select** it: `config(['blog-manager.media.adapter' => 'medialibrary'])` (or publish + edit the config).

## Rules
- The adapter touches the **binary only** — validation, the `media_items` record, and reference-safe deletion
  stay in `MediaManager`.
