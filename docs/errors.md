# Error codes

Every failure throws a subclass of `Aristonis\BlogManager\Exceptions\BlogManagerException`, which carries a
**numeric code** and a **string code** (plus optional context) and implements
`Aristonis\BlogManager\Contracts\HasErrorCode`. These two values are a **stable public contract** — clients may
switch on them.

When the caller **expects JSON** (`Accept: application/json`), the exception self-renders to:

```json
{ "error_code": 3001, "error_key": "blog.media.validation_failed", "message": "..." }
```

Otherwise `render()` returns `null` and Laravel handles the exception normally — the package **never overrides
the host's global exception handler**.

## Ranges
`1xxx` posts · `2xxx` blocks · `3xxx` media · `4xxx` authorization · `9xxx` generic.

## Catalog (v0.1)
| Code | Key | HTTP | Exception | When |
|------|-----|------|-----------|------|
| 1001 | `blog.post.not_found` | 404 | `PostNotFoundException` | Post missing by public id/slug |
| 1002 | `blog.post.invalid_data` | 422 | `InvalidPostDataException` | Bad post attributes |
| 2001 | `blog.block.type_not_registered` | 422 | `BlockTypeNotRegisteredException` | Unknown block type |
| 2002 | `blog.block.invalid_data` | 422 | `InvalidBlockDataException` | Block payload failed type validation |
| 2003 | `blog.block.kind_mismatch` | 422 | `BlockKindMismatchException` | Media kind ≠ block type |
| 2004 | `blog.block.position_out_of_range` | 422 | `BlockPositionOutOfRangeException` | Reorder target outside [0, n-1] |
| 3001 | `blog.media.validation_failed` | 422 | `MediaValidationException` | Disallowed MIME or oversize |
| 3002 | `blog.media.adapter_not_found` | 500 | `MediaAdapterNotFoundException` | Configured media driver missing |
| 3003 | `blog.media.in_use` | 409 | `MediaInUseException` | Delete refused — still referenced |
| 3004 | `blog.media.storage_failed` | 500 | `MediaStorageFailedException` | Adapter store/delete failed |
| 4001 | `blog.authorization.denied` | 403 | `AuthorizationDeniedException` | Ability denied |
| 9001 | `blog.error` | 500 | `GenericBlogManagerException` | Unexpected fallback |

Add a new error by extending `BlogManagerException` with its own `NUMBER_CODE` + `TEXT_CODE` constants and an
`$httpStatus` — no central registry to edit.
