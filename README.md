# laravel-blog-manager

A backend Laravel package for managing blog posts as **ordered content blocks** — interleaving text and media
(images, videos, files) in the exact authored order — with a **pluggable, contracts-first media layer** and
**pluggable authorization**. It is a **core-only backend by design** (no frontend, no HTTP layer): your app owns
the UI **and the transport** — drive the package through the `BlogManager` facade / services and wire your own
controllers or API over them.

> Status: **v0.3 in development.** Requires PHP ^8.2 and Laravel 12 or 13.

## Install

```bash
composer require aristonis/laravel-blog-manager
php artisan vendor:publish --tag=blog-manager-config
```

The service provider is auto-discovered. See **[docs/getting-started.md](docs/getting-started.md)**.

## Documentation — two audiences

- **For developers → [`docs/`](docs/)** — human guides: getting started, usage, configuration, architecture,
  extending, error codes, events.
- **For AI agents → [`.ai/`](.ai/)** — agent skills describing how to *use and extend* this package correctly:
  start at [`.ai/index.md`](.ai/index.md), rules in [`.ai/conventions.md`](.ai/conventions.md).

## What it does

- Posts composed of ordered, typed blocks: **Heading · Paragraph · Image · Video · File**.
- **Publishing lifecycle:** draft / published, with **scheduling** (a future publish date) — computed, no cron.
- **Revisions:** append-only snapshots of a post (auto on publish + manual) with **non-destructive restore** —
  re-upload flow for media deleted since the snapshot. No diff engine, no autosave.
- Paragraph text stored as `plain`/`markdown`, rendered to **sanitized HTML**. Every block is served as a
  **`{ source, payload }`** pair (raw data + rendered output) so any frontend can re-theme.
- **Media Manager** with a swappable storage adapter (default: Laravel filesystem) — register your own
  (e.g. `spatie/laravel-medialibrary`) without touching the core.
- **Pluggable authorization** (default allow-all; `gate` or a custom driver) — the package defines ability keys,
  never roles/permissions. Enforce in your transport, or inside the services via `enforce_in_services`.
- **Core-only, no HTTP layer** — the host owns its transport and API contract. Numbered-code exceptions and
  after-commit domain events are the integration surface for host code.

## License

MIT. See [LICENSE](LICENSE).
