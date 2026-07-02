# laravel-blog-manager

A backend Laravel package for managing blog posts as **ordered content blocks** — interleaving text and media
(images, videos, files) in the exact authored order — with a **pluggable, contracts-first media layer** and
**pluggable authorization**. It is **backend-only by design** (no frontend, ever): your app owns the UI and drives
the package through the services or the optional JSON API.

> Status: **v0.2 in development.** Requires PHP ^8.2 and Laravel 12 or 13.

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
- **JSON API contract → [`docs/openapi.yaml`](docs/openapi.yaml)** (OpenAPI 3.1). Driving it from a decoupled
  frontend: [`.ai/skills/drive-the-blog-from-any-frontend.md`](.ai/skills/drive-the-blog-from-any-frontend.md).

## What it does

- Posts composed of ordered, typed blocks: **Heading · Paragraph · Image · Video · File**.
- **Publishing lifecycle:** draft / published, with **scheduling** (a future publish date) — computed, no cron.
- Paragraph text stored as `plain`/`markdown`, rendered to **sanitized HTML**. Every block is served as a
  **`{ source, payload }`** pair (raw data + rendered output) so any frontend can re-theme.
- **Media Manager** with a swappable storage adapter (default: Laravel filesystem) — register your own
  (e.g. `spatie/laravel-medialibrary`) without touching the core.
- **Pluggable authorization** (default allow-all; `gate` or a custom driver) — the package defines ability keys,
  never roles/permissions. Public reads are published-only under a restricting driver.
- Optional off-by-default **JSON API** over the same services; numbered-code exceptions and after-commit domain
  events for host integration.

## License

MIT. See [LICENSE](LICENSE).
