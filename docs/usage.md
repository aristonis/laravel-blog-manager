# Usage

> Core-only: call the services from your app code. The package ships no HTTP layer — your app owns the
> transport (web controllers, a JSON API, Livewire, CLI) over these same services.

## Facade and services
Resolve the `BlogManager` facade or the individual services (`PostService`, `BlockService`, `MediaManager`).

```php
use Aristonis\BlogManager\Facades\BlogManager;

// Posts
$post = BlogManager::posts()->create(['title' => 'Hello world', 'author_id' => $user->id]);
$post = BlogManager::posts()->find('hello-world');          // by slug or public id
BlogManager::posts()->update($post, ['title' => 'Hello, world']);
BlogManager::posts()->delete($post);                        // cascades blocks, keeps media
$page = BlogManager::posts()->paginate(15);                 // all posts
$page = BlogManager::posts()->paginate(15, onlyPublished: true); // published + past published_at only

// Publishing lifecycle (draft by default). Scheduling is computed — a future
// published_at is Published but not visible until then; no separate state, no cron.
BlogManager::posts()->publish($post);                       // publish now
BlogManager::posts()->publish($post, now()->addDay());      // schedule
BlogManager::posts()->unpublish($post);                     // back to draft

// Blocks (appended in authored order)
BlogManager::blocks()->append($post, 'heading', ['text' => 'Intro', 'level' => 1]);
BlogManager::blocks()->append($post, 'paragraph', ['format' => 'markdown', 'content' => '**hi**']);
BlogManager::blocks()->update($block, ['content' => 'edited']);
BlogManager::blocks()->remove($block);                      // remaining blocks re-sequenced
BlogManager::blocks()->reorder($post, $orderedPublicIds);   // full list, new order

// Media (store first, then reference from a media block)
$image = BlogManager::media()->store($request->file('photo'));
BlogManager::blocks()->append($post, 'image', ['alt' => 'A photo', 'caption' => 'Nice'], $image);
BlogManager::media()->delete($image);                       // refused while a block references it
```

## Rendering
`render()` returns an ordered list of blocks, each carrying both the raw **`source`** (the stored data) and the
sanitized **`payload`** (safe HTML / resolved media URL) — so a frontend can drop in the HTML or re-theme from
the source:

```php
$blocks = BlogManager::render(BlogManager::posts()->find('hello-world'));
// [ ['id'=>..., 'type'=>'paragraph', 'position'=>0,
//    'source'  => ['format'=>'markdown', 'content'=>'**hi**'],
//    'payload' => ['format'=>'markdown', 'html'=>'<p><strong>hi</strong></p>']], ... ]
```

> **`source` is raw, unsanitized author input** — for client-side re-rendering/re-theming. Sanitize it before
> injecting as HTML. `payload` is already sanitized (safe to render directly).

## Events
Each mutation dispatches an after-commit event you can listen for: `PostCreated/Updated/Deleted`,
`PostPublished/Unpublished`, `BlockAppended/Updated/Removed`, `BlocksReordered`, `MediaStored/Deleted`.
The package ships no listeners. See [events.md](events.md).

## Building your own transport
The package ships no controllers or routes — you wire your own. Call the services from a web controller, a
JSON API, a Livewire component, or a console command; you own the URL shape, auth, pagination, and response
envelope. Enforce abilities either in your transport layer or inside the services (set
`authorization.enforce_in_services=true`). Published-only reads: pass `onlyPublished: true` to
`paginate()` / `find()`, and treat a hidden post as a **404** in your controller. Package errors self-render as
JSON `{ "error_code", "error_key", "message" }` when the client expects JSON (see [errors.md](errors.md)).

## Configuration & authorization
See [configuration.md](configuration.md). Authorization is pluggable and off by default — see
[../.ai/skills/add-authorizer.md](../.ai/skills/add-authorizer.md).
