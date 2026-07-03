# .ai — AI agent skills

Hidden folder of **skills for AI agents** working on this package. If you are an agent, read these before
changing code — they encode how to use and extend the package correctly and fast.

## Read order
1. **[conventions.md](conventions.md)** — the hard rules. Non-negotiable. Read first.
2. Task skills (added per step-group in `skills/`):
   - `skills/using-the-package.md` — create a post, add ordered blocks, attach media (SG-7).
   - `skills/add-block-type.md` — register a new block type without editing the core (SG-4).
   - `skills/add-media-adapter.md` — register a media storage adapter (SG-5).
   - `skills/add-authorizer.md` — register an authorization driver (SG-6).
   - `skills/manage-revisions.md` — capture, list, and non-destructively restore post revisions (v0.3).
   - `skills/manage-taxonomy.md` — classify posts with categories & tags, and read posts by term (v0.4).
   - `skills/run-tests.md` — Pest, Pint, Larastan commands (SG-9 finalized).

## Orientation
- **Package:** `Aristonis\BlogManager\` (PSR-4 in `src/`). Composer: `aristonis/laravel-blog-manager`.
- **Entry:** `BlogManager` facade / `blog-manager` container binding → domain services.
- **Human docs:** [`../docs/`](../docs/). **Design source of truth:** the wrapper brain (outside this repo).
