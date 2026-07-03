# Skill — Manage post revisions

How to use the revision history: capture, list, fetch, and non-destructively restore a post version.
Core-only — call the `RevisionService` (via the `BlogManager` facade or the container). No HTTP layer.

## Model
- A **revision** is an immutable JSON snapshot of a post: its attributes **+** the whole ordered block tree.
  Media is referenced **by id, not copied** — the binary stays in the media store.
- History is **append-only**: snapshots are written once, never mutated.

## Capture
```php
BlogManager::revisions()->snapshot($post, $label = null, $createdBy = null);
```
- Dispatches `PostRevisionCreated` after commit.
- **Auto-capture on publish:** `PostService::publish()` records a `published` revision unless
  `config('blog-manager.revisions.snapshot_on_publish')` is false.
- Retention: `config('blog-manager.revisions.keep')` — `null` = unlimited; an int prunes the oldest beyond N.

## Read
```php
BlogManager::revisions()->for($post);              // Collection, newest first
BlogManager::revisions()->for($post, perPage: 20); // LengthAwarePaginator
BlogManager::revisions()->get($post, $revisionPublicId); // scoped; foreign/absent → RevisionNotFoundException
```

## Restore (non-destructive, append-only)
```php
BlogManager::revisions()->restore($post, $revision);                          // content only
BlogManager::revisions()->restore($post, $revision, restorePublishState: true); // also status/published_at
```
- Captures the **pre-restore** state first (never lost), rebuilds attributes + block tree, then dispatches
  `PostRestored` after commit. Restored blocks are **new rows** (content preserved, not their public ids).
- **Content-only by default** — it does not change `status`/`published_at` (so a restore never silently
  re-publishes) unless `restorePublishState: true`.

## Missing media on restore
If a snapshot references media that was deleted since:
- Default (`on_missing_media = strict`): throws **`RevisionMediaMissingException`**; its `context()['missing']`
  lists each gap (`position`, `type`, `original_filename`, `media_public_id`). Surface it, have the user
  re-upload, then retry with a remap:
  ```php
  $new = BlogManager::media()->store($file);
  BlogManager::revisions()->restore($post, $revision, mediaRemap: [$oldMediaPublicId => $new->public_id]);
  ```
  The repaired restore records a fresh revision.
- `on_missing_media = lenient`: drops the missing-media block(s) and restores the rest.

## Media deletion rule
`MediaManager::delete()` is refused while a **live** block references the item; media referenced **only by
history** is deletable (the gap is handled at restore, above). Do not add snapshot scanning to delete.

## Hard rules
- Never mutate an existing revision row — history is append-only.
- Restore/snapshot are guarded by the `blog.post.update` ability (enforced when `enforce_in_services=true`).
- Keep transactions in the service; a failed restore rolls back whole (atomic).
