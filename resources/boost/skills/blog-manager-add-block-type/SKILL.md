---
name: blog-manager-add-block-type
description: Register a new content block type in aristonis/laravel-blog-manager without editing the core (OCP) by implementing the BlockType contract and registering it on BlockTypeRegistry. Use when adding a custom block kind (quote, embed, gallery, etc.).
---

# Skill — add a block type

Goal: add a new kind of content block **without editing the core** (OCP).

## Steps
1. **Implement the contract** `Aristonis\BlogManager\Contracts\BlockType`:
   - `key()` — the string type stored on `content_blocks.type` (unique).
   - `validate(array $data): array` — validate + normalize the payload; **throw
     `InvalidBlockDataException`** on bad input (never return silently-wrong data).
   - `requiresMediaKind(): ?MediaKind` — return a `MediaKind` for media blocks (the service enforces the match),
     or `null` for text blocks.
   - `renderPayload(array $data, ?string $mediaUrl): array` — return a **sanitized** payload
     (escape with `e()`, or `Str::markdown($x, ['html_input' => 'strip', 'allow_unsafe_links' => false])`).
   - For a media block, extend `Aristonis\BlogManager\Blocks\Types\MediaBlockType` instead — it handles
     caption/alt validation + rendering; you only declare `key()` and `requiresMediaKind()`.

2. **Register it** from your app's service provider `boot()`:
   ```php
   use Aristonis\BlogManager\Blocks\BlockTypeRegistry;

   $this->app->make(BlockTypeRegistry::class)->register(new QuoteType);
   ```

3. Use the new `key()` when appending blocks. Done — no package file changed.

## Rules
- Never edit `BlockTypeRegistry` or the shipped types to add one — register instead.
- Rendered output must be XSS-safe; author input is untrusted.
- Keep persistence in `content_blocks.data` (payload only); media is referenced by FK, not embedded.
