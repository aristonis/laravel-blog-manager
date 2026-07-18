# Changelog

All notable changes to `aristonis/laravel-blog-manager` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-07-18

### Added
- **Laravel Boost integration.** The package now ships AI guidelines and skills under `resources/boost/`
  in the [Laravel Boost](https://laravel.com/docs/boost) standard layout: an always-on `core` guideline plus
  seven on-demand skills (`blog-manager-usage`, `-add-block-type`, `-add-media-adapter`, `-add-authorizer`,
  `-revisions`, `-taxonomy`, `-seo`). Hosts that use Boost auto-discover them via `php artisan boost:install`
  or `boost:update --discover`.

### Removed
- The ad-hoc `.ai/` agent-docs folder. Its content moved into `resources/boost/`, now the single source of
  truth for AI-agent context; README and docs links repointed accordingly.
- The in-package `docs/` folder. Human guides moved to the
  [documentation site](https://aristonis.org/open-source/laravel-blog-manager/docs); README links repointed there.

## [1.0.0] - 2026-07-12

Initial release.

Backend-only blog management for Laravel: posts with ordered content blocks, a publishing
lifecycle, revisions, taxonomy, per-post SEO, and a swappable media layer. No HTTP layer;
drive it through the `BlogManager` facade. Laravel 12/13, PHP 8.2+.

See the README for the full feature list.
