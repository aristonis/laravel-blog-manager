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
`1xxx` posts · `2xxx` blocks · `3xxx` media · `4xxx` authorization · `5xxx` taxonomy · `6xxx` seo · `9xxx` generic.
Revision errors reuse the post/media ranges (1003, 3005).

## Catalog
| Code | Key | HTTP | Exception | When |
|------|-----|------|-----------|------|
| 1001 | `blog.post.not_found` | 404 | `PostNotFoundException` | Post missing by public id/slug |
| 1002 | `blog.post.invalid_data` | 422 | `InvalidPostDataException` | Bad post attributes |
| 1003 | `blog.revision.not_found` | 404 | `RevisionNotFoundException` | Revision missing by id |
| 2001 | `blog.block.type_not_registered` | 422 | `BlockTypeNotRegisteredException` | Unknown block type |
| 2002 | `blog.block.invalid_data` | 422 | `InvalidBlockDataException` | Block payload failed type validation |
| 2003 | `blog.block.kind_mismatch` | 422 | `BlockKindMismatchException` | Media kind ≠ block type |
| 2004 | `blog.block.position_out_of_range` | 422 | `BlockPositionOutOfRangeException` | Reorder target outside [0, n-1] |
| 3001 | `blog.media.validation_failed` | 422 | `MediaValidationException` | Disallowed MIME or oversize |
| 3002 | `blog.media.adapter_not_found` | 500 | `MediaAdapterNotFoundException` | Configured media driver missing |
| 3003 | `blog.media.in_use` | 409 | `MediaInUseException` | Delete refused — still referenced |
| 3004 | `blog.media.storage_failed` | 500 | `MediaStorageFailedException` | Adapter store/delete failed |
| 3005 | `blog.revision.media_missing` | 422 | `RevisionMediaMissingException` | Restore blocked — referenced media gone |
| 4001 | `blog.authorization.denied` | 403 | `AuthorizationDeniedException` | Ability denied |
| 4002 | `blog.authorization.driver_not_found` | 500 | `AuthorizationDriverNotFoundException` | Configured authorization driver missing |
| 5001 | `blog.category.not_found` | 404 | `CategoryNotFoundException` | Category missing by public id/slug |
| 5002 | `blog.tag.not_found` | 404 | `TagNotFoundException` | Tag missing by public id, slug, or name |
| 5003 | `blog.taxonomy.invalid_data` | 422 | `InvalidTaxonomyDataException` | Empty name or duplicate category name |
| 6001 | `blog.seo.invalid_data` | 422 | `InvalidSeoDataException` | Unknown SEO field, non-string override, or a value over its length cap |
| 9001 | `blog.error` | 500 | `GenericBlogManagerException` | Unexpected fallback |

Add a new error by extending `BlogManagerException` with its own `NUMBER_CODE` + `TEXT_CODE` constants and an
`$httpStatus` — no central registry to edit.
