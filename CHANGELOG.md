# Changelog

All notable changes to `aristonis/laravel-blog-manager` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
