# Changelog

All notable changes to `aristonis/laravel-blog-manager` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-12

The first tagged release. It consolidates the M1 correctness/concurrency/data-at-scale
hardening pass, the M2 **per-post SEO metadata**, and the M3 pre-1.0 freeze (a
configurable **author key type** and a `MediaSource` **media-input contract** — a
breaking `MediaStorageAdapter` port change), plus the Milestone C release-gate
hardening (FR-86..91). Core-only — no HTTP layer; drive it through the `BlogManager`
facade.

### BREAKING — `MediaStorageAdapter` port input (Milestone 3)
- **`MediaStorageAdapter::store()` now takes a `MediaSource`, not an `UploadedFile`.** The
  first parameter changed from `Illuminate\Http\UploadedFile $file` to
  `Aristonis\BlogManager\Media\MediaSource $source` (return type `StoredMediaRef` unchanged).
  **Custom-adapter migration:** if you authored a `MediaStorageAdapter`, update your `store()`
  signature to `store(MediaSource $source, MediaKind $kind): StoredMediaRef` and read the binary
  from `$source->path` **or** `$source->stream()` (exactly one is set) instead of the old `$file`;
  the `stream` is exposed via the `stream()` reader method (the property is `private` — the VO is
  immutable). The caller-supplied `$source->mime` / `$source->originalFilename` / `$source->size` replace the
  `UploadedFile` accessors. **Do not close a supplied stream** — the caller owns and closes the
  resource it opened (the adapter only reads it). This break was made pre-1.0, while the
  package was still untagged, and ships as part of this first tagged release.

### Added — Release gate (Milestone C)
- **`MediaManager::orphanedLazy(): LazyCollection`** — a streaming counterpart to
  `orphaned()`. It cursors the same anti-join so a host can reclaim orphaned media on a
  large media table without materialising the whole result set in memory.
- **`SlugExhaustedException`** (`9002` / `blog.slug.exhausted` / 500) — a typed domain
  exception for an exhausted slug generation / collision-retry budget. Replaces the raw
  `QueryException` that could leak on a lost slug race.

### Changed — Release gate (Milestone C)
- **`ResolvedSeo` moved from `Blocks\` to `Seo\`.** It is now
  `Aristonis\BlogManager\Seo\ResolvedSeo`. A host importing it must update its `use`. This
  is pre-1.0, so not a SemVer break, but it is called out here because the tag freezes the
  namespace.

### Fixed — Release gate (Milestone C)
- **Slug-race collisions surface a typed exception, never a raw `QueryException`.** Post
  create/update, tag auto-create, and revision restore wrap their slug derive + write in a
  bounded collision retry that re-derives a fresh slug on a lost race and, only on
  exhaustion, throws `SlugExhaustedException`.
- **`MediaManager::delete()` no longer double-dispatches `MediaDeleted`.** When the locked
  row is already gone (a concurrent already-committed delete won the `FOR UPDATE`),
  `delete()` early-returns, so the event fires exactly once across the racers.
- **`MediaManager::orphaned()` uses a `whereNotExists` anti-join** (was `whereNotIn` over a
  subquery), dropping a redundant `whereNotNull` guard. Same result set, index-driven and
  portable across SQLite/MySQL/Postgres.

### Added — Author key type + media source (Milestone 3)
- **Configurable author key type.** New config `blog-manager.author_key_type` ∈
  `{bigint, uuid, ulid}` (default `bigint`), declared once and applied to **both**
  `blog_posts.author_id` and `blog_post_revisions.created_by` at migrate time — no DB foreign
  key either way. The `bigint` default is byte-for-byte the previous schema. An unknown value
  fails loud (`InvalidArgumentException`) at both application bootstrap and migrate time (before
  any table is created — no partial migration, no silent fallback).
- **`MediaSource` value object + `MediaManager::storeSource()`.** New immutable
  `Media\MediaSource` (exactly one of a filesystem `path` XOR an open `stream`, plus
  `mime`/`originalFilename`/`size`) is now the storage port's input, letting a host ingest media
  from any binary source, not only an HTTP upload. `MediaManager::storeSource(MediaSource)` is the
  primary entry (guard → validate → store → record → `MediaStored`); `MediaManager::store(UploadedFile)`
  is retained as a behavior-preserving convenience overload that builds a `MediaSource` and
  delegates. The caller-supplied MIME is trusted (never re-sniffed), and a `size: 0` source means
  unknown length and skips the max-size cap (documented behavior, not a silent bypass).

### Added — SEO metadata (Milestone 2)
- **Per-post SEO overrides + resolver.** New `blog_post_seo` table (1:1, cascade-on-delete,
  `unique(post_id)`), a `PostSeo` model, and `Post::seo()` / `Post::firstParagraph()` `hasOne`
  relations. Overrides: `meta_title`, `meta_description`, `canonical_url`, `noindex`, `nofollow`,
  `og_title`, `og_description`, `og_image`, `og_type`.
- **`SeoService`** (`BlogManager::seo()`): `set` (full replace — omitted fields reset), `update`
  (partial), `for` (raw row), and `resolve` → a readonly **`ResolvedSeo`** meta-bag with a
  fallback chain (meta over post title, meta/og description over a first-paragraph excerpt,
  `og_title` over the page title **without** changing `<title>`, `og_type` over config). Writes are
  guard-first (`blog.post.update`), trim + fail-loud length-capped (no silent truncation), and
  unique-violation-retry safe.
- **No tags emitted (by design).** `ResolvedSeo` is scalars + a symmetric `toArray()`; the host
  serializes it into `<meta>`/`<title>`/robots/canonical/OG. No JSON-LD or sitemap.
- **Feed contract:** eager-load `->with(['seo', 'firstParagraph'])` so `resolve()` stays a constant
  2 loads, size-independent (never an N+1 on blocks).
- **Event** `PostSeoUpdated` (after-commit). **Exception** `InvalidSeoDataException`
  (`6001` / `blog.seo.invalid_data` / 422; new `6xxx` SEO range). **Config** `tables.post_seo`
  and `seo.{default_og_type, excerpt_length}`.

### Changed
- **`revisions.keep` default is now `20`** (was `null` = unlimited) so revision history
  cannot grow unbounded out of the box. Set `revisions.keep => null` to restore unlimited
  retention. Hosts that published their config before this release keep their own value —
  set it explicitly.
- **`MediaDeleted` now dispatches after the outermost transaction commits** (was inside the
  delete transaction). A listener that assumed atomicity with the row delete sees a small
  timing shift; the binary removal is likewise deferred to after commit.

### Added
- **Orphaned-media reclamation query** — `MediaManager::orphaned()` lists media items no live
  content block references (a read-only seam; returns storage internals, so gate external
  exposure behind admin authorization).
- **Data-at-scale indexes** — composite `(status, published_at)` on `blog_posts`, plus explicit
  PostgreSQL-safe FK indexes on `content_blocks.media_item_id` and `blog_post_revisions
  (post_id, id)` (Postgres does not auto-index FK columns).

### Fixed
- **Mass-assignment lockdown** — structural/internal columns (`disk`/`path`/`adapter`,
  `post_id`/`type`, `snapshot`, `public_id`) removed from every model's `$fillable`; services
  set them explicitly.
- **Block-position concurrency** — `append`/`reorder` guard `position` with a row lock and a
  bounded unique-violation retry, translating collisions to a typed exception.
- **Media deletion is rollback- and nesting-safe** — in-transaction re-check under a row lock;
  binary + event deferred to the true outermost commit.
- **Revision restore preserves block identity** (re-seats `public_id`), re-resolves each block
  type through the registry (fail-loud on unknown), and surfaces `slugChanged` on `PostRestored`.
- **Render N+1 removed** — `render()` eager-loads `blocks.mediaItem`.
- **Taxonomy attach/detach events fire only on a real change** (no phantom event on a no-op sync).
- **Render hardening** — heading `level` clamped to 1–6; media `url` escaped; category-name
  unique-violation and `temporaryUrl`-unsupported disks degrade to typed/safe results; MIME
  control-chars stripped from error messages; post titles trimmed.
- **Pagination stability** — the published feed breaks a `published_at` tie by `id`.
- **Tag public-id resolution is case-insensitive**; slug uniquification is capped with a random
  fallback so it always terminates.

## [0.4.0] - unreleased

Adds **taxonomy** — classify posts with categories and tags — on top of the core-only
service package. No HTTP layer; drive it through the `BlogManager` facade.

### Added — Taxonomy
- **Two-axis classification.** Curated **categories** (unique names, must pre-exist) and
  free-form **tags** (repeatable, auto-created on attach). New `Category` / `Tag` models +
  `blog_categories` / `blog_tags` tables and `blog_post_category` / `blog_post_tag` pivots
  (`unique(post_id, term_id)`); additive `Post::categories()` / `Post::tags()`.
- **`TaxonomyService`** (`BlogManager::taxonomy()`): term lifecycle (`create`/`rename`/
  `delete` category & tag), attach/detach (`categorize`/`tag` + `sync*` / `uncategorize` /
  `untag`), and reads (`for`, `categories`, `tags`, `postsByCategory`, `postsByTag`,
  `getCategory`, `getTag`). Slugs are per-table-unique and stable across renames (shared
  `SlugGenerator`, extracted from the Post/Revision duplication).
- **Direct-membership reads.** `postsBy*` returns posts attached via the pivot
  (newest-first, no descendant rollup); `onlyPublished` filters through the publishing scope.
- **Authorization.** New `blog.taxonomy.manage` ability guards the term catalog; attaching a
  post's terms reuses `blog.post.update`. Auto-creating a tag while tagging a post rides on
  `blog.post.update` (tags are free-form) — not the term-management ability.
- **Events** `Category/Tag Created/Updated/Deleted`, `PostCategorized`, `PostTagged`
  (after-commit).
- **Config** `taxonomy.tags.auto_create` (default true) and `tables.{categories, tags,
  post_category, post_tag}`.

### Security / Integrity
- **Unique category names enforced at the database** (not just the service) — the
  curated-name invariant can't be raced by concurrent creates.
- **`public_id` is not mass-assignable** on `Category`/`Tag` — the opaque ULID is
  always package-generated, never host-supplied.
- **Term-membership indexes** on the pivots (`category_id`/`tag_id` leading) and on
  `blog_tags.name` — the by-term and attach-by-name reads stay indexed on PostgreSQL,
  not just MySQL.
- **Stable newest-first pagination** — the published read branch breaks a `published_at`
  tie by id so posts can't skip/duplicate across page boundaries.

### Notes
- Flat categories only — nesting (with descendant rollup) is deferred.
- Pre-first-release: schema changes edit the original create-table migrations in place.

## [0.3.0] - unreleased

Adds a **post revision history** on top of the core-only repositioning. The package
ships domain services and a facade — no HTTP layer; each host owns its own transport
**and** API contract.

### Added — Revisions
- **Append-only revision history.** A revision is a full immutable JSON snapshot of a
  post — its attributes plus the whole ordered block tree (media referenced by id, not
  copied). New `PostRevision` model + `blog_post_revisions` table, `Post::revisions()`.
- **`RevisionService`** (`BlogManager::revisions()`): `snapshot()`, `for()`, `get()`,
  `restore()`.
- **Auto-capture on publish** — publishing records a `published` revision (toggle
  `revisions.snapshot_on_publish`); manual `snapshot()` is always available.
- **Non-destructive restore.** Rebuilds attributes + block tree from a snapshot, keeps
  the pre-restore state (append-only), and is **content-only by default** (never silently
  re-publishes) unless `restorePublishState: true`.
- **Media-gap repair on restore.** A snapshot referencing since-deleted media throws
  `RevisionMediaMissingException` with a per-gap list; re-upload and pass a `mediaRemap`
  to complete and record a fresh revision. `revisions.on_missing_media=lenient` drops the
  gap instead. Media deletion is unchanged: refused while a live block references it,
  allowed when only history does.
- **Events** `PostRevisionCreated` / `PostRestored` (after-commit).
- **Config** `revisions.{snapshot_on_publish, keep, on_missing_media}`; retention prunes
  the oldest beyond `keep` per post.

### Removed — BREAKING
- **The optional HTTP API is gone (D25).** Deleted `src/Http/**` (Post/Block/Media
  controllers + resources, `EnsureAbility` middleware), `routes/api.php`, the `api.*`
  config block, `docs/openapi.yaml`, and the `drive-the-blog-from-any-frontend` recipe.

### Changed
- Drive the blog through the `BlogManager` facade / the services and wire your own
  controllers, JSON API, Livewire, or CLI over them.
- Authorization: with `enforce_in_services=false` (default) the host authorizes in its
  own transport; set it `true` to enforce abilities inside the services on every mutation.

### Retained (unchanged)
- Publishing lifecycle (draft/published + computed scheduling), the `{ source, payload }`
  block contract, DB `unique(post_id, position)` + two-phase reorder, after-commit domain
  events, and numbered-code self-rendering exceptions.

## [0.2.0] - unreleased

Backend-only, headless: the client owns the frontend (any tool, any theme). This
release makes the package a first-class headless backend — a publishing lifecycle
plus a frontend-agnostic content contract — without shipping any UI.

### Added
- **Publishing lifecycle.** Posts gain a `status` (`draft` default) and a nullable
  `published_at`. `PostService::publish($post, ?$at)` / `unpublish($post)`; a future
  `published_at` **schedules** a post (Published but not yet visible) — computed at
  read time, **no cron/queue**.
- **Domain events** `PostPublished` / `PostUnpublished` (after-commit).
- **Published-only public reads.** API `index`/`show` return only visible posts to
  callers lacking `blog.post.update`; a hidden post is a **404**, never a 403.
  Callers holding the ability (author view) still see drafts/scheduled.
- **API** `POST posts/{post}/publish` and `POST posts/{post}/unpublish`
  (ability `blog.post.update`); `status` + `published_at` in `PostResource`.
- **Frontend-agnostic block contract.** Every block is served as `{ source, payload }`
  — raw stored data alongside the sanitized rendered output — so any frontend can
  re-theme instead of only consuming server HTML.
- **API contract docs.** `docs/openapi.yaml` (OpenAPI 3.1) and the
  `.ai/skills/drive-the-blog-from-any-frontend.md` recipe.

### Changed
- `BlockResource` shape: the raw-only `attributes` key is replaced by the
  `{ source, payload }` pair (consistent with the rendered read contract).
- `PostService::find()` / `paginate()` accept an `onlyPublished` filter.

### Security / Integrity
- Content-block position invariant now enforced at the **database** —
  `unique(post_id, position)` — with a **two-phase reorder** to avoid transient
  collisions inside the transaction (previously service-only).

### Notes
- Supported: **Laravel 12 & 13**, PHP ^8.2.
- Pre-first-release: schema changes edit the original create-table migrations in
  place (no ALTER migrations). Hosts on the 0.1 baseline re-run migrations.

## [0.1.0] - baseline (not publicly released)

Initial backend package: posts with ordered typed content blocks
(heading/paragraph/image/video/file), contracts-first Media Manager with a
swappable adapter registry, pluggable authorization (ability keys only),
numbered-code exceptions, after-commit domain events, and an optional
off-by-default JSON API.
