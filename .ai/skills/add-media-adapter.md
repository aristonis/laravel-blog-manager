# Skill — add a media storage adapter

Goal: store media through a different backend (e.g. `spatie/laravel-medialibrary`, a CDN, S3 with custom logic)
**without editing the core** (OCP).

## Steps
1. **Implement** `Aristonis\BlogManager\Contracts\MediaStorageAdapter`:
   - `name()` — the driver key.
   - `store(UploadedFile $file, MediaKind $kind): StoredMediaRef` — persist the **binary only** and return a
     `StoredMediaRef($adapter, $disk, $path, $meta)`. Never write the DB record — the `MediaManager` does that.
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
- Return everything needed to resolve/remove the file later in the `StoredMediaRef` (`disk`/`path` or `meta`).
- Storing is `validate -> store -> record`; if you throw from `store()`, no record is created.
