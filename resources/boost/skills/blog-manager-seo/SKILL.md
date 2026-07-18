---
name: blog-manager-seo
description: Attach per-post SEO overrides and resolve them into a host-serializable meta-bag with aristonis/laravel-blog-manager's SeoService — the package stores and resolves values with fallbacks but never emits markup; the host renders the tags. Use when setting meta title/description, canonical, robots, or Open Graph data for a post.
---

# Skill — Manage SEO metadata

How to attach per-post SEO overrides and resolve them into a host-serializable meta-bag. Core-only — call the
`SeoService` (via the `BlogManager` facade or the container). **The package stores and resolves values; it
never emits tags.**

## Model
- **1:1 satellite:** each post has at most one `blog_post_seo` row (`Post::seo()` `hasOne`, cascade on
  post-delete). It has no `public_id` — it is always reached via its post, never independently addressable.
- **`ResolvedSeo`** is a readonly value object (flat typed props + a nested `toArray()`) — the resolved bag
  after fallbacks. It carries **no Eloquent and no markup**.
- Overrides are the user-meaningful fields only: `meta_title`, `meta_description`, `canonical_url`, `noindex`,
  `nofollow`, `og_title`, `og_description`, `og_image`, `og_type`. `og_image` is a plain URL string (the host
  owns the media source — no dependency on the media layer).

## Write (guarded by `blog.post.update`)
```php
BlogManager::seo()->set($post, $data);     // FULL replace — an omitted field resets to default/null
BlogManager::seo()->update($post, $data);  // PARTIAL — only the provided keys change
```
- **`set` vs `update`:** `set` is a full upsert — any fillable field you omit is cleared (string → null,
  `noindex`/`nofollow` → false). `update` touches only the keys you pass. Use `update` to flip one flag without
  wiping the rest.
- **Validation (fail-loud, `InvalidSeoDataException` 6001 / 422):** every string override is **trimmed**, and
  empty/whitespace-only is stored as **null** (so a host can't set a blank `<title>`). Length caps are enforced
  (no silent truncation) — `META_TITLE_MAX = 255`, `META_DESCRIPTION_MAX = 500`, `URL_MAX = 2048`
  (canonical_url + og_image), `OG_TYPE_MAX = 64` (public constants on `SeoService`). An **unknown field key**
  or a non-string override also throws 6001.
- Guard order is **guard-first**: `blog.post.update` is checked before validation, one `DB::transaction`,
  then `PostSeoUpdated($post)` after commit. A concurrent first-write race on `unique(post_id)` is caught and
  retried as an update.

## Read (unguarded — authorize post access yourself)
```php
BlogManager::seo()->for($post);      // ?PostSeo — the raw stored row, or null if unset
BlogManager::seo()->resolve($post);  // ResolvedSeo — the resolved meta-bag with fallbacks (pure, no writes)
```

### `resolve()` fallback chain
```
title        = meta_title       ?? post.title
description  = meta_description  ?? excerpt(first paragraph)   // ?? null
canonicalUrl = canonical_url                                  // ?? null — host builds its own default
noindex      = seo?.noindex ?? false
nofollow     = seo?.nofollow ?? false
ogTitle       = og_title        ?? title        // og_title affects og:title ONLY, never the page <title>
ogDescription = og_description  ?? description
ogImage       = og_image                        // ?? null — no auto first-image
ogType        = og_type         ?? config('blog-manager.seo.default_og_type', 'article')
```
- `excerpt` = the first `paragraph` block's text, markup-stripped, mb-safe word-boundary truncated to
  `config('blog-manager.seo.excerpt_length', 155)`. No paragraph (or an empty one) → `null` description.
- **`resolve()` is pure:** no writes, deterministic, idempotent.

### `ResolvedSeo` shape (pinned 1.0 contract)
```php
// flat props
title: string   description: ?string   canonicalUrl: ?string
noindex: bool    nofollow: bool
ogTitle: string  ogDescription: ?string  ogImage: ?string  ogType: string

// toArray() — symmetric nesting
[
  'title'        => string,
  'description'  => ?string,
  'canonicalUrl' => ?string,
  'robots'       => ['noindex' => bool, 'nofollow' => bool],
  'og'           => ['title' => string, 'description' => ?string, 'image' => ?string, 'type' => string],
]
```

## Feed eager-load (stay N+1-free)
`resolve()` reads `seo` and — only when no meta/og description is set — the post's **first paragraph** for the
excerpt. When resolving a list, eager-load **both** so it stays a constant **2 loads, size-independent**:
```php
$posts = Post::query()->with(['seo', 'firstParagraph'])->paginate(15);
foreach ($posts as $post) {
    $meta = BlogManager::seo()->resolve($post);   // no per-post query
}
```
The old `->with('seo')`-only recipe would N+1 on blocks for every no-description post — always load both.

## The no-tags contract (host responsibility)
- **The package emits no markup.** `ResolvedSeo` is scalars + `toArray()`. There is no `<meta>`, `<title>`,
  robots, canonical, JSON-LD, or sitemap output anywhere in the package (C-11).
- **You serialize the DTO to tags** in your own Blade/view/response layer. Escape values as you would any
  attribute output. Example: `description → <meta name="description">`, `robots → <meta name="robots">`,
  `canonicalUrl → <link rel="canonical">`, the `og` group → `<meta property="og:*">`.
- **Validate URL schemes for `canonical_url` / `og_image`.** HTML-escaping alone does not neutralize a
  `javascript:`/`data:` scheme when the value is placed in an `href`/`src`/redirect target, so check the scheme
  (expect `http`/`https`) before using it there — the standard `<link rel="canonical">` / `<meta property="og:image">`
  attribute contexts are safe.
- **Authorize post access before reads** — `for()`/`resolve()` are unguarded at the service layer (house rule,
  like `PostService::find()`). Only resolve posts the caller may see.
- Guards apply only when `authorization.enforce_in_services = true`; otherwise authorize upstream in your transport.

## Hard rules
- `og_*` never changes the page `<title>` — page title and social title are separate (`ogTitle` falls back to
  `title`, not the other way round).
- `set` clears omitted fields; reach for `update` when you mean a partial change.
- Never mass-assign `post_id` — it is structural, set by the relation.
- Transactions live in the service; a failed write rolls back whole (no partial row, no event).
