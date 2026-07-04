# Events

Every successful mutation dispatches a domain event **after the database transaction commits**
(`Illuminate\Contracts\Events\ShouldDispatchAfterCommit`) — nothing fires on rollback. The package ships **no
listeners**; these exist for your app to hook into.

| Event | Payload | When |
|-------|---------|------|
| `Events\PostCreated` | `Post $post` | A post is created |
| `Events\PostUpdated` | `Post $post` | A post's attributes change |
| `Events\PostDeleted` | `Post $post` | A post (and its blocks) is deleted |
| `Events\PostPublished` | `Post $post` | A post is published (or scheduled) |
| `Events\PostUnpublished` | `Post $post` | A post is returned to draft |
| `Events\BlockAppended` | `ContentBlock $block` | A block is appended |
| `Events\BlockUpdated` | `ContentBlock $block` | A block's payload changes |
| `Events\BlockRemoved` | `ContentBlock $block` | A block is removed (others re-sequenced) |
| `Events\BlocksReordered` | `Post $post` | A post's blocks are reordered |
| `Events\MediaStored` | `MediaItem $media` | A media item is stored |
| `Events\MediaDeleted` | `MediaItem $media` | A media item is deleted |
| `Events\PostRevisionCreated` | `PostRevision $revision` | A revision is captured (manual snapshot or auto on publish) |
| `Events\PostRestored` | `Post $post`, `PostRevision $revision` | A post is restored from a revision |
| `Events\CategoryCreated` | `Category $category` | A category is created |
| `Events\CategoryUpdated` | `Category $category` | A category is renamed |
| `Events\CategoryDeleted` | `Category $category` | A category is deleted (pivots detached) |
| `Events\TagCreated` | `Tag $tag` | A tag is created (directly or auto-created on attach) |
| `Events\TagUpdated` | `Tag $tag` | A tag is renamed |
| `Events\TagDeleted` | `Tag $tag` | A tag is deleted (pivots detached) |
| `Events\PostCategorized` | `Post $post`, `Category[] $added`, `Category[] $removed` | A post's categories change (one delta per op) |
| `Events\PostTagged` | `Post $post`, `Tag[] $added`, `Tag[] $removed` | A post's tags change (one delta per op) |
| `Events\PostSeoUpdated` | `Post $post` | A post's SEO metadata is set or updated (`SeoService::set`/`update`) |

## Listening
```php
use Aristonis\BlogManager\Events\PostCreated;
use Illuminate\Support\Facades\Event;

Event::listen(PostCreated::class, function (PostCreated $event) {
    // $event->post->public_id ...
});
```
