# Extending

Every extension is a **registration**, never an edit to the package core (OCP). Step-by-step recipes live in the
AI-agent skills and apply equally to humans:

- **Add a block type** → [../resources/boost/skills/blog-manager-add-block-type/SKILL.md](../resources/boost/skills/blog-manager-add-block-type/SKILL.md)
  Implement `Contracts\BlockType` (or extend `Blocks\Types\MediaBlockType`), then
  `app(BlockTypeRegistry::class)->register(new MyType)` from your provider.

- **Add a media storage adapter** → [../resources/boost/skills/blog-manager-add-media-adapter/SKILL.md](../resources/boost/skills/blog-manager-add-media-adapter/SKILL.md)
  Implement `Contracts\MediaStorageAdapter`, then
  `app(MediaAdapterManager::class)->extend('key', fn () => new MyAdapter)` and set `media.adapter`.
  The port's input is a `Media\MediaSource` (`store(MediaSource $source, MediaKind $kind): StoredMediaRef`) —
  read `$source->path` **or** `$source->stream` (exactly one is set), **never close a supplied stream** (the
  caller owns it), and treat `$source->size === 0` as unknown length.

- **Add an authorization driver** → [../resources/boost/skills/blog-manager-add-authorizer/SKILL.md](../resources/boost/skills/blog-manager-add-authorizer/SKILL.md)
  Implement `Contracts\Authorizer`, then
  `app(AuthorizationManager::class)->extend('key', fn () => new MyAuthorizer)` and set `authorization.driver`.

- **Add an error type** → extend `Exceptions\BlogManagerException` with its own `NUMBER_CODE` + `TEXT_CODE`
  constants and `$httpStatus`. See [errors.md](errors.md).

## Ground rules
- Rendered output must be XSS-safe; author input is untrusted.
- Services own transactions; models stay pure CRUD.
- Identify everything by opaque `public_id` (ULID), never the numeric key.

Run the checks after any change:
```bash
composer test && composer lint && composer analyse
```
