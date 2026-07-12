# Upgrade guide

## To 1.0.0

This is the first tagged release. It consolidates the pre-1.0 milestones (M1 hardening,
M2 SEO metadata, M3 author-key-type + `MediaSource`) and the Milestone C release gate.
The items below are the host-facing changes that need action. Everything else is additive.

### `revisions.keep` — set it explicitly if you published the config

The shipped default for `revisions.keep` changed from `null` (unlimited) to `20`, so
revision history no longer grows unbounded out of the box. A published `config/blog-manager.php`
keeps its own value — the new shipped default does not reach a config you published before
this change. If you published the config earlier, set `revisions.keep` explicitly to the
retention you want (`20`, another cap, or `null` to keep the old unlimited behaviour). Hosts
that never published the config get the new `20` default automatically.

### `ResolvedSeo` moved to the `Seo\` namespace

`ResolvedSeo` moved from `Aristonis\BlogManager\Blocks\ResolvedSeo` to
`Aristonis\BlogManager\Seo\ResolvedSeo`. If your view or transport imports the resolved
meta-bag by its fully-qualified name, update the `use`:

```php
- use Aristonis\BlogManager\Blocks\ResolvedSeo;
+ use Aristonis\BlogManager\Seo\ResolvedSeo;
```

The class shape (a readonly value object with a symmetric `toArray()`) is unchanged. This
is pre-1.0, so it is not a SemVer break, but the tag freezes the namespace from here on.

### `MediaStorageAdapter` port input changed (BREAKING, from M3)

If you authored a custom `MediaStorageAdapter`, its `store()` now takes a `MediaSource`
instead of an `UploadedFile`:

```php
- public function store(UploadedFile $file, MediaKind $kind): StoredMediaRef
+ public function store(MediaSource $source, MediaKind $kind): StoredMediaRef
```

Read the binary from `$source->path` **or** `$source->stream()` — exactly one is set. Use
`$source->mime`, `$source->originalFilename`, and `$source->size` in place of the old
`UploadedFile` accessors. Do **not** close a supplied stream: the caller owns and closes the
resource it opened; the adapter only reads it. See the BREAKING note in
[CHANGELOG.md](../CHANGELOG.md) for the full contract.

### Additive-migration rule (from 1.0 on)

Before 1.0, schema and index changes edited the original create-table migrations in place,
so a host had to re-run migrations to pick them up. From 1.0 on (NFR-34), any schema or index
change ships as a **new guarded migration**, never an edit to an existing create-migration. A
host that has already run the package migrations is safe: upgrading pulls in the new migrations
and `php artisan migrate` applies only the additions. You never re-run or roll back a
create-migration to upgrade.
