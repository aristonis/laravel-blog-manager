# Extending

Every extension is a **registration**, never an edit to the package core (OCP). Step-by-step recipes live in the
AI-agent skills and apply equally to humans:

- **Add a block type** → [../.ai/skills/add-block-type.md](../.ai/skills/add-block-type.md)
  Implement `Contracts\BlockType` (or extend `Blocks\Types\MediaBlockType`), then
  `app(BlockTypeRegistry::class)->register(new MyType)` from your provider.

- **Add a media storage adapter** → [../.ai/skills/add-media-adapter.md](../.ai/skills/add-media-adapter.md)
  Implement `Contracts\MediaStorageAdapter`, then
  `app(MediaAdapterManager::class)->extend('key', fn () => new MyAdapter)` and set `media.adapter`.

- **Add an authorization driver** → [../.ai/skills/add-authorizer.md](../.ai/skills/add-authorizer.md)
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
