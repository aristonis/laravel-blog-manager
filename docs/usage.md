# Usage

> Backend-only core: call the services from your app code. An **optional** JSON API (off by default) is
> documented below.

## Facade and services
Resolve the `BlogManager` facade or the individual services (`PostService`, `BlockService`, `MediaManager`).

```php
use Aristonis\BlogManager\Facades\BlogManager;

// Posts
$post = BlogManager::posts()->create(['title' => 'Hello world', 'author_id' => $user->id]);
$post = BlogManager::posts()->find('hello-world');          // by slug or public id
BlogManager::posts()->update($post, ['title' => 'Hello, world']);
BlogManager::posts()->delete($post);                        // cascades blocks, keeps media
$page = BlogManager::posts()->paginate(15);

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
`render()` returns an ordered list of sanitized payloads — text as safe HTML, media with a resolved URL:

```php
$blocks = BlogManager::render(BlogManager::posts()->find('hello-world'));
// [ ['id'=>..., 'type'=>'heading', 'position'=>0, 'payload'=>['level'=>1,'html'=>'<h1>Intro</h1>']], ... ]
```

## Events
Each mutation dispatches an after-commit event you can listen for: `PostCreated/Updated/Deleted`,
`BlockAppended/Updated/Removed`, `BlocksReordered`, `MediaStored/Deleted`. The package ships no listeners.

## Optional HTTP API
Disabled by default. Enable it and set the host guard middleware via config (published `config/blog-manager.php`):

```php
'api' => [
    'enabled' => true,
    'prefix' => 'blog/api',
    'middleware' => ['api', 'auth:sanctum'], // your auth stack
    'rate_limit' => '60,1',
],
```

Endpoints (under the configured prefix; ids are opaque ULIDs):

| Method | Path | Ability |
|--------|------|---------|
| GET | `posts` | — (open) |
| GET | `posts/{post}` | — (open, returns rendered blocks) |
| POST | `posts` | `blog.post.create` |
| PUT | `posts/{post}` | `blog.post.update` |
| DELETE | `posts/{post}` | `blog.post.delete` |
| POST | `posts/{post}/blocks` | `blog.block.manage` |
| POST | `posts/{post}/blocks/reorder` | `blog.block.manage` |
| PUT | `blocks/{block}` | `blog.block.manage` |
| DELETE | `blocks/{block}` | `blog.block.manage` |
| POST | `media` (multipart `file`) | `blog.media.upload` |
| DELETE | `media/{media}` | `blog.media.delete` |

Write abilities are enforced through the pluggable authorizer (default `none` = allow-all); reads are open.
Package errors render as JSON `{ "error_code", "error_key", "message" }` (see [errors.md](errors.md)).

## Configuration & authorization
See [configuration.md](configuration.md). Authorization is pluggable and off by default — see
[../.ai/skills/add-authorizer.md](../.ai/skills/add-authorizer.md).
