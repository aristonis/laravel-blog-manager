# laravel-blog-manager

A backend Laravel package for managing blog posts as **ordered content blocks** — interleaving text and media
(images, videos, files) in the exact authored order — with a **pluggable, contracts-first media layer** and
**pluggable authorization**. It is a **core-only backend by design** (no frontend, no HTTP layer): your app owns
the UI **and the transport** — drive the package through the `BlogManager` facade / services and wire your own
controllers or API over them.

> Status: **v1.0.** Supports Laravel 12 and 13 on PHP ^8.2. Core-only — no HTTP layer;
> drive it through the `BlogManager` facade.

## Install

```bash
composer require aristonis/laravel-blog-manager
php artisan vendor:publish --tag=blog-manager-config
```

The service provider is auto-discovered. See **[docs/getting-started.md](docs/getting-started.md)**.

## Documentation — two audiences

- **For developers → [`docs/`](docs/)** — human guides: getting started, usage, configuration, architecture,
  extending, error codes, events.
- **For AI agents → [Laravel Boost](https://laravel.com/docs/boost)** — this package ships Boost guidelines and
  skills in [`resources/boost/`](resources/boost/) that describe how to *use and extend* it correctly. Install
  Boost in your app and run `php artisan boost:install` (or `boost:update --discover`); your coding agent then
  auto-loads the always-on guideline plus the on-demand `blog-manager-*` skills.

## What it does

- Posts composed of ordered, typed blocks: **Heading · Paragraph · Image · Video · File**.
- **Publishing lifecycle:** draft / published, with **scheduling** (a future publish date) — computed, no cron.
- **Revisions:** append-only snapshots of a post (auto on publish + manual) with **non-destructive restore** —
  re-upload flow for media deleted since the snapshot. No diff engine, no autosave.
- **Taxonomy:** classify posts with **categories** (curated, unique) and **tags** (free-form, auto-created),
  and read posts by term. Flat in v0.4 (nesting deferred). Guarded by `blog.taxonomy.manage`.
- **SEO metadata:** per-post meta/OpenGraph overrides + a pure **resolver** that returns a flat, typed meta-bag
  (`ResolvedSeo`) with fallbacks (post title, first-paragraph excerpt, config `og:type`). The package emits **no
  tags** — you serialize the DTO into `<meta>` in your own view.
- Paragraph text stored as `plain`/`markdown`, rendered to **sanitized HTML**. Every block is served as a
  **`{ source, payload }`** pair (raw data + rendered output) so any frontend can re-theme.
- **Media Manager** with a swappable storage adapter (default: Laravel filesystem) — register your own
  (e.g. `spatie/laravel-medialibrary`) without touching the core.
- **Any binary source (`MediaSource`):** ingest media from a filesystem path or an open stream — not only an
  HTTP `UploadedFile` — via `storeSource(MediaSource)`; the one-line `store(UploadedFile)` path stays for the
  common case. The caller owns/closes any supplied stream.
- **Configurable author key type:** match the host `User` primary key — `author_key_type` ∈ `bigint` (default)
  · `uuid` · `ulid`, declared once and applied to both author columns, with no DB foreign key.
- **Pluggable authorization** (default allow-all; `gate` or a custom driver) — the package defines ability keys,
  never roles/permissions. Enforce in your transport, or inside the services via `enforce_in_services`.
- **Core-only, no HTTP layer** — the host owns its transport and API contract. Numbered-code exceptions and
  after-commit domain events are the integration surface for host code.

## License

MIT. See [LICENSE](LICENSE).
