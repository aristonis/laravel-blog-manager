# Getting started

> Human documentation. For AI-agent usage, this package ships [Laravel Boost](https://laravel.com/docs/boost)
> guidelines and skills in [`../resources/boost/`](../resources/boost/).

## Requirements
- PHP `^8.2`
- Laravel `12` or `13`

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
- `authorization` — `none` (default) | `gate` | a custom driver.

## Publish and run migrations

```bash
php artisan vendor:publish --tag=blog-manager-migrations
php artisan migrate
```

This creates `blog_posts`, `blog_media_items`, and `blog_content_blocks` (names overridable via
`config('blog-manager.tables')`). Usage examples land in [usage.md](usage.md) (SG-7/9).

## Documentation map
| Doc | Contents | Status |
|-----|----------|--------|
| getting-started.md | install + publish | ✅ |
| configuration.md | full config reference | ✅ |
| usage.md | services/facade recipes | ✅ |
| architecture.md | components, ports, data model | ✅ |
| extending.md | add block type / media adapter / authorizer | ✅ |
| errors.md | numbered error-code table | ✅ |
| events.md | event catalog | ✅ |
