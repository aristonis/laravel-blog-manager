# Events

Every successful mutation dispatches a domain event **after the database transaction commits**
(`Illuminate\Contracts\Events\ShouldDispatchAfterCommit`) — nothing fires on rollback. The package ships **no
listeners**; these exist for your app to hook into.

| Event | Payload | When |
|-------|---------|------|
| `Events\PostCreated` | `Post $post` | A post is created |
| `Events\PostUpdated` | `Post $post` | A post's attributes change |
| `Events\PostDeleted` | `Post $post` | A post (and its blocks) is deleted |
| `Events\BlockAppended` | `ContentBlock $block` | A block is appended |
| `Events\BlockUpdated` | `ContentBlock $block` | A block's payload changes |
| `Events\BlockRemoved` | `ContentBlock $block` | A block is removed (others re-sequenced) |
| `Events\BlocksReordered` | `Post $post` | A post's blocks are reordered |
| `Events\MediaStored` | `MediaItem $media` | A media item is stored |
| `Events\MediaDeleted` | `MediaItem $media` | A media item is deleted |

## Listening
```php
use Aristonis\BlogManager\Events\PostCreated;
use Illuminate\Support\Facades\Event;

Event::listen(PostCreated::class, function (PostCreated $event) {
    // $event->post->public_id ...
});
```
