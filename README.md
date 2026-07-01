# laravel-blog-manager

A backend Laravel package for managing blog posts as **ordered content blocks** — interleaving text and media
(images, videos, files) in the exact authored order — with a **pluggable, contracts-first media layer** and
**pluggable authorization**. v0.1 is backend-only (no frontend); an optional JSON API ships off by default.

> Status: **v0.1 in development.** Requires PHP ^8.2 and Laravel 11 or 12.

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

## What it does (v0.1)

- Posts composed of ordered, typed blocks: **Heading · Paragraph · Image · Video · File**.
- Paragraph text stored as `plain`/`markdown`, rendered to **sanitized HTML**.
- **Media Manager** with a swappable storage adapter (default: Laravel filesystem) — register your own
  (e.g. `spatie/laravel-medialibrary`) without touching the core.
- **Pluggable authorization** (default allow-all; `gate` or a custom driver) — the package defines ability keys,
  never roles/permissions.
- Numbered-code exceptions and after-commit domain events for host integration.

## License

MIT. See [LICENSE](LICENSE).
