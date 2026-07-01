# Conventions — the hard rules

Read before touching code. These are load-bearing; violating them breaks the package's design contract.

## Architecture
- **Eloquent models are pure persistence** (relations + accessors only). **All transactions live in the
  services** (`PostService`, `BlockService`, `MediaManager`) — never in models or controllers.
- **Extension happens at registries, never by editing the core.** Adding a block type, a media adapter, or an
  authorization driver is a **registration**, not a change to a `match`/`switch` or a shared class (OCP).
  - Block types → `BlockTypeRegistry` (implement `BlockType`).
  - Media storage → `MediaAdapterManager` (implement `MediaStorageAdapter`, register a driver).
  - Authorization → `AuthorizationManager` (implement `Authorizer`, register a driver).
- **Ports are faked in tests.** Build/verify the core with fakes before real adapters.

## Data & identity
- **Public identifiers are opaque ULIDs** (`public_id`). Never expose the numeric primary key via services or API.
- **Media is referenced by a real FK** (`content_blocks.media_item_id`), not embedded in JSON.
- **Block positions are unique + contiguous** (`0..n-1`) within a post after every add/remove/reorder — always
  re-sequenced inside a transaction.

## Behavior
- **Fail loud.** Validation and integrity failures throw; never swallow or silently fall back.
- **Text is sanitized on render.** Paragraph markdown → `Str::markdown(html_input: 'strip', allow_unsafe_links: false)`;
  plain → `e()`. No raw HTML block.
- **Media deletion is refused while referenced** by any block.
- **Secure file default:** the `file`-kind MIME allow-list is **empty by default**; the host opts in via
  `config('blog-manager.media.allowed_mime.file')`. Image/video have safe defaults.
- **Events dispatch after commit** (`ShouldDispatchAfterCommit`); the package ships no listeners.
- **Authorization defines ability keys only** — never model or store roles/permissions. Default driver `none`
  allows all; enforcement is at the API edge unless `authorization.enforce_in_services` is on.

## Exceptions
- Every error throws a subclass of **`BlogManagerException`**, carrying a **numeric code** and a **string code**
  (no enum) plus context, and self-renders to JSON for the API. Do not override the host's global handler.
- Number ranges: `1xxx` posts · `2xxx` blocks · `3xxx` media · `4xxx` authorization · `9xxx` generic.

## Config over hardcoding
- Table names, media disk/path, allow-lists/size caps, API toggle, authorization driver — all from
  `config('blog-manager.*')`, read at call time. No magic numbers.

## Definition of done (every change)
- Test-first (Pest). Coverage ≥ 80% on logic.
- `./vendor/bin/pint` clean · `./vendor/bin/phpstan analyse` clean.
- One commit per step-group; Conventional Commits; no attribution trailer.
