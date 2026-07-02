# Changelog

All notable changes to `aristonis/laravel-blog-manager` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
