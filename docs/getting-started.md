# Getting started

> Human documentation. For AI-agent usage, see [`../.ai/`](../.ai/).

## Requirements
- PHP `^8.2`
- Laravel `11` or `12`

## Install

```bash
composer require aristonis/laravel-blog-manager
```

The provider `Aristonis\BlogManager\BlogManagerServiceProvider` is auto-discovered.

## Publish configuration

```bash
php artisan vendor:publish --tag=blog-manager-config
```

This copies `config/blog-manager.php` into your app. Key options (full reference in
[configuration.md](configuration.md), added in SG-9):

- `author_model` — the host model posts may reference as author (nullable).
- `tables` — override table names to avoid collisions.
- `media` — storage adapter, disk/path, allowed MIME + size caps per kind.
- `api` — enable/prefix/middleware/throttle for the optional JSON API.
- `authorization` — `none` (default) | `gate` | a custom driver.

Migrations publish from SG-3; usage examples land in [usage.md](usage.md) (SG-7/9).

## Documentation map
| Doc | Contents | Status |
|-----|----------|--------|
| getting-started.md | install + publish | ✅ |
| configuration.md | full config reference | SG-9 |
| usage.md | services/facade recipes | SG-7/9 |
| architecture.md | components, ports, data model | SG-9 |
| extending.md | add block type / media adapter / authorizer | SG-9 |
| errors.md | numbered error-code table | SG-2/9 |
| events.md | event catalog | SG-7/9 |
