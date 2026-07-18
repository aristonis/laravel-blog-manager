# Aristonis Blog Manager

`aristonis/laravel-blog-manager` is a **core-only** blog engine: posts, ordered content blocks (text + media),
revisions, taxonomy, and SEO. It ships **no HTTP layer** — the host drives it through the `BlogManager` facade
(or the underlying services) and owns its own transport, routes, and API contract.

## Orientation
- **Namespace:** `Aristonis\BlogManager\` (PSR-4, `src/`). Composer: `aristonis/laravel-blog-manager`.
- **Entry point:** the `Aristonis\BlogManager\Facades\BlogManager` facade / the `blog-manager` container binding
  → domain services (`PostService`, `BlockService`, `MediaManager`, `RevisionService`, `TaxonomyService`, `SeoService`).
- **Config:** everything tunable lives under `config('blog-manager.*')`, read at call time.

For task recipes, the matching agent skill (`blog-manager-usage`, `blog-manager-add-block-type`,
`blog-manager-add-media-adapter`, `blog-manager-add-authorizer`, `blog-manager-revisions`,
`blog-manager-taxonomy`, `blog-manager-seo`) is loaded on demand.

## The hard rules (non-negotiable — violating them breaks the design contract)

### Architecture
- **Eloquent models are pure persistence** (relations + accessors only). **All transactions live in the
  services** — never in models or callers.
- **Core-only, no HTTP layer (D25).** No controllers, routes, resources, or HTTP middleware. Never re-add an
  in-package API; the host owns transport.
- **Extend at registries, never by editing the core** (OCP). Adding a block type, a media adapter, or an
  authorization driver is a **registration**, not a change to a `match`/`switch` or a shared class.
  - Block types → `BlockTypeRegistry` (implement `BlockType`).
  - Media storage → `MediaAdapterManager` (implement `MediaStorageAdapter`, register a driver).
  - Authorization → `AuthorizationManager` (implement `Authorizer`, register a driver).
- **Ports are faked in tests.** Build/verify the core with fakes before real adapters.

### Data & identity
- **Public identifiers are opaque ULIDs** (`public_id`). Never expose the numeric primary key from the services.
- **Media is referenced by a real FK** (`content_blocks.media_item_id`), not embedded in JSON.
- **Block positions are unique + contiguous** (`0..n-1`) within a post after every add/remove/reorder — always
  re-sequenced inside a transaction.

### Behavior
- **Fail loud.** Validation and integrity failures throw; never swallow or silently fall back.
- **Text is sanitized on render.** Paragraph markdown → `Str::markdown(html_input: 'strip', allow_unsafe_links: false)`;
  plain → `e()`. No raw HTML block.
- **Media deletion is refused while referenced** by any live block.
- **Secure file default:** the `file`-kind MIME allow-list is **empty by default**; the host opts in via
  `config('blog-manager.media.allowed_mime.file')`. Image/video have safe defaults.
- **Events dispatch after commit** (`ShouldDispatchAfterCommit`); the package ships no listeners.
- **Authorization defines ability keys only** — never model or store roles/permissions. Default driver `none`
  allows all; the host enforces in its own transport unless `authorization.enforce_in_services` is on (then the
  services enforce on every mutation).

### Exceptions
- Every error throws a subclass of **`BlogManagerException`**, carrying a **numeric code** and a **string code**
  (no enum) plus context, and self-renders to a JSON error when the client expects JSON. Do not override the
  host's global handler.
- Number ranges: `1xxx` posts · `2xxx` blocks · `3xxx` media · `4xxx` authorization · `9xxx` generic.

### Config over hardcoding
- Table names, media disk/path, allow-lists/size caps, authorization driver — all from `config('blog-manager.*')`,
  read at call time. No magic numbers.
