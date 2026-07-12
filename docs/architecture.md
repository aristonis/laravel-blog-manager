# Architecture

A modular Laravel package: Eloquent persistence + transaction-owning services, with three **pluggable seams**
(registries) at exactly the points meant for extension. The hard rules live in
[../.ai/conventions.md](../.ai/conventions.md).

## Layers
- **Models** (`Models/`) — `Post`, `ContentBlock`, `MediaItem`. Pure persistence: relations, casts, opaque ULID
  `public_id` (numeric key never exposed). Media is referenced by a real FK.
- **Services** (`Services/`, `Media/MediaManager`) — `PostService`, `BlockService`, `MediaManager`. **Own all
  transactions**, enforce invariants, dispatch events. This is where business rules live.
- **Facade** (`BlogManager`) — composition root: `posts()`, `blocks()`, `media()`, `render()`.

## Seams (register, never edit the core — OCP)
- **Block types** — `Contracts\BlockType` + `Blocks\BlockTypeRegistry`. Default: heading/paragraph/image/video/file.
  Rendering (`Blocks\BlockRenderer`) sanitizes: markdown via `Str::markdown(strip)`, plain via `e()`.
- **Media storage** — `Contracts\MediaStorageAdapter` + `Media\MediaAdapterManager` (Illuminate Manager). Default:
  `FilesystemAdapter`. `MediaManager` validates → stores → records, compensating the binary on record failure.
  The port's input is the immutable `Media\MediaSource` value object — exactly one of a `path` XOR a `stream`
  plus caller-supplied `mime`/`originalFilename`/`size` — so the core is decoupled from HTTP: `storeSource(MediaSource)`
  is the primary entry, and `store(UploadedFile)` is a convenience overload that builds a `MediaSource` and
  delegates. An adapter reads a supplied stream but **never closes** it (the caller owns the resource); `size: 0`
  means unknown length and skips the max-size cap; the MIME is caller-supplied and never re-sniffed from bytes.
- **Authorization** — `Contracts\Authorizer` + `Authorization\AuthorizationManager`. Drivers: `none` (default),
  `gate`, or custom. Ability **keys** only; never models roles/permissions.

## Cross-cutting
- **Exceptions** — `Exceptions\BlogManagerException` carries a numeric + string code and self-renders to JSON.
  See [errors.md](errors.md).
- **Events** — nine `ShouldDispatchAfterCommit` events; no listeners shipped. See [events.md](events.md).

## No HTTP layer (core-only)
The package ships **no controllers, routes, resources, or HTTP middleware** (D25). It is a service/facade core;
the host wires its own transport (web controllers, JSON API, Livewire, CLI — whatever it needs) over the same
services, and owns its own API contract and business rules.

The `Illuminate\Http` imports in `Exceptions\BlogManagerException::render()` (`JsonResponse`/`Request`) are
**not** a residual API surface under D25. They are exception ergonomics: `render()` returns a JSON body only when
the caller `expectsJson()`, and returns `null` otherwise so Laravel's own handler runs. It never registers a
route or overrides the host's global exception handler.

## Migrations — additive from 1.0 (NFR-34)
Before 1.0, schema and index changes edited the original create-table migrations in place. From the 1.0 tag on,
any schema or index change ships as a **new guarded migration**, never an edit to an existing create-migration.
A host that has already run the package migrations upgrades by pulling the new migrations and running
`php artisan migrate` — no re-run or rollback of a create-migration. See [UPGRADE.md](UPGRADE.md).

## Data model
```
Post (1) ──< ContentBlock (ordered by position) >── (0..1) MediaItem
  public_id ULID           type, position, data(json), media_item_id FK
  title, slug, author_id?  (image/video/file reference a MediaItem)
```
Positions are unique + contiguous per post, maintained by `BlockService`. Deleting a post cascades its blocks;
media items are independent and retained. See [../.ai/conventions.md](../.ai/conventions.md) for the full rules.
