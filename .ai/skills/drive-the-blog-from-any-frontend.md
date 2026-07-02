# Skill — drive the blog from any frontend

The package is backend-only: it ships **no UI**. A decoupled frontend (Vue/React/mobile) or a same-process
Blade/Livewire view drives it entirely through the services or the optional JSON API. The full contract is in
[`../../docs/openapi.yaml`](../../docs/openapi.yaml) (OpenAPI 3.1).

## Which surface
- **Same-process frontend** (Blade, Livewire, Inertia): call the services/facade directly — no HTTP needed.
  See [using-the-package.md](using-the-package.md).
- **Decoupled frontend** (JS SPA, mobile, another service): use the JSON API below.

## Enable the API
Off by default. In the published `config/blog-manager.php`:
```php
'api' => [
    'enabled' => true,
    'prefix' => 'blog/api',
    'middleware' => ['api', 'auth:sanctum'], // your auth stack
    'rate_limit' => '60,1',
],
```
Cross-origin frontend? Configure Laravel's native CORS (`config/cors.php`) — the package ships none.

## The content contract (this is the key part)
Every block is a **`{ source, payload }`** pair, so the frontend chooses how to render:
- `source` — the raw stored data (e.g. `{ "format": "markdown", "content": "**hi**" }`). Render/re-theme it yourself.
- `payload` — the sanitized, presentation-ready output (e.g. `{ "format": "markdown", "html": "<p>...</p>" }`).
  Drop it straight into the DOM.

`GET posts/{id}` returns the post plus its blocks in authored order:
```json
{ "data": { "id": "01J…", "title": "…", "status": "published", "published_at": "…",
  "blocks": [ { "id": "01J…", "type": "paragraph", "position": 0,
               "source": { "format": "markdown", "content": "**hi**" },
               "payload": { "format": "markdown", "html": "<p><strong>hi</strong></p>" } } ] } }
```

## Publishing & visibility
- New posts are `draft`. `POST posts/{id}/publish` (optional `published_at`) publishes or **schedules** (a future
  date). `POST posts/{id}/unpublish` returns to draft.
- **Public reads show published posts only.** A caller holding `blog.post.update` (author view) also sees
  drafts/scheduled; a hidden post is a **404**, never a 403. Visibility follows the active authorizer — with the
  default `none` driver every caller is an author, so configure a real driver for a public site.

## Typical flow
1. `POST media` (multipart `file`) → get a media `id`.
2. `POST posts` → draft post.
3. `POST posts/{id}/blocks` with `{ type, data, media_id? }` per block (append order = authored order).
4. `POST posts/{id}/blocks/reorder` with the full `order` list if needed.
5. `POST posts/{id}/publish`.
6. Frontend reads `GET posts` / `GET posts/{id}` and renders `payload` (or re-themes `source`).

## Rules
- Ids are **opaque ULIDs** — never expose or guess numeric ids.
- Write operations require an ability (`blog.post.*`, `blog.block.manage`, `blog.media.*`); reads are open but
  visibility-filtered.
- Errors are a stable envelope `{ error_code, error_key, message }` — switch on `error_code` (see
  [../../docs/errors.md](../../docs/errors.md)).
