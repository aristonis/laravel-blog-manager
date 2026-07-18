# .ai — package-dev workspace

The consumer-facing AI context (guidelines + skills) now lives in **`resources/boost/`** in the
[Laravel Boost](https://laravel.com/docs/13.x/boost) standard layout, so a host app that installs this package
and runs `php artisan boost:install` / `boost:update` picks it up automatically. That directory is the **single
source of truth** — do not duplicate it here.

## Shipped to consumers (via Boost)
- **Guidelines (always-on):** [`resources/boost/guidelines/core.blade.php`](../resources/boost/guidelines/core.blade.php)
  — orientation + the hard rules.
- **Skills (on-demand):** [`resources/boost/skills/`](../resources/boost/skills/)
  - `blog-manager-usage` — create a post, add ordered blocks, attach media.
  - `blog-manager-add-block-type` — register a new block type without editing the core.
  - `blog-manager-add-media-adapter` — register a media storage adapter.
  - `blog-manager-add-authorizer` — register an authorization driver.
  - `blog-manager-revisions` — capture, list, and non-destructively restore post revisions.
  - `blog-manager-taxonomy` — classify posts with categories & tags, and read posts by term.
  - `blog-manager-seo` — attach per-post SEO metadata and resolve it to a host-serializable meta-bag.

## Package-dev only (NOT shipped)
- [`conventions.md`](conventions.md) — pointer to the shipped guideline (the hard rules).
- [`skills/run-tests.md`](skills/run-tests.md) — Pest / Pint / Larastan commands for developing **this** package.

## Orientation
- **Package:** `Aristonis\BlogManager\` (PSR-4 in `src/`). Composer: `aristonis/laravel-blog-manager`.
- **Entry:** `BlogManager` facade / `blog-manager` container binding → domain services.
- **Human docs:** [`../docs/`](../docs/). **Design source of truth:** the wrapper brain (outside this repo).
