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

## Revisions (history & restore)
A post keeps an **append-only history of immutable snapshots** — its attributes plus the whole ordered block
tree (media is referenced by id, never copied). Capture is explicit and also automatic on publish; restore is
non-destructive.

```php
// Capture a snapshot (optionally labelled + an author id for attribution)
BlogManager::revisions()->snapshot($post, 'before big edit', $userId);

// Publishing auto-captures a 'published' revision (toggle: revisions.snapshot_on_publish)
BlogManager::posts()->publish($post);

// List (newest first) and fetch one
$history = BlogManager::revisions()->for($post);          // or ->for($post, perPage: 20)
$revision = BlogManager::revisions()->get($post, $revisionPublicId);

// Restore — content only by default (does NOT change publish state or author); append-only
BlogManager::revisions()->restore($post, $revision);
BlogManager::revisions()->restore($post, $revision, restorePublishState: true);
```

**Missing media on restore.** If a snapshot references media that was since deleted, `restore()` throws
`RevisionMediaMissingException` (default `revisions.on_missing_media = strict`), whose context carries a
`missing` list (block position, type, original filename, old media public id). Re-upload the files, then retry
with a remap — the restore completes and records a fresh revision:

```php
$new = BlogManager::media()->store($request->file('replacement'));
BlogManager::revisions()->restore($post, $revision, mediaRemap: [
    $oldMediaPublicId => $new->public_id,
]);
```

Set `revisions.on_missing_media = lenient` to instead drop the missing-media block and restore the rest.
Deleting media is unchanged: refused while a **live** block references it, allowed when only history does.

## Taxonomy (categories & tags)
Classify posts along two independent axes. **Categories** are curated (unique names, must pre-exist);
**tags** are free-form (names may repeat, auto-created on attach by default). Managing the term catalog is
guarded by `blog.taxonomy.manage`; attaching/detaching a post's terms reuses `blog.post.update`.

```php
// Manage the term catalog (guarded by blog.taxonomy.manage)
$news = BlogManager::taxonomy()->createCategory('News');          // unique name; slug derived
$php  = BlogManager::taxonomy()->createTag('PHP');                // free-form
BlogManager::taxonomy()->renameCategory($news, 'Breaking News');  // slug stays unless you pass one
BlogManager::taxonomy()->deleteTag($php);                         // detaches pivots; posts survive

// Attach to a post (guarded by blog.post.update). Categories by model or public id;
// tags by model, public id, or name (auto-created when taxonomy.tags.auto_create is on).
BlogManager::taxonomy()->categorize($post, [$news]);              // idempotent add
BlogManager::taxonomy()->tag($post, ['php', 'laravel']);          // names -> find-or-create
BlogManager::taxonomy()->syncCategories($post, [$news]);          // replace the whole set
BlogManager::taxonomy()->uncategorize($post, [$news]);
BlogManager::taxonomy()->untag($post, [$php]);

// Read
$terms = BlogManager::taxonomy()->for($post);                     // ['categories' => ..., 'tags' => ...]
$all   = BlogManager::taxonomy()->categories();                   // flat, name-ordered
$posts = BlogManager::taxonomy()->postsByCategory($news, onlyPublished: true); // newest-first, direct members
$cat   = BlogManager::taxonomy()->getCategory('news');            // by public id or slug
```

Membership is **direct only** — `postsByCategory`/`postsByTag` return posts attached via the pivot, with no
descendant rollup. Reads honor publishing visibility when you pass `onlyPublished: true`. Auto-creating a tag
while tagging a post rides on `blog.post.update` (not `blog.taxonomy.manage`) — tags are free-form.

## SEO metadata
Attach per-post SEO overrides (meta/OpenGraph title & description, canonical URL, robots flags, `og:image`,
`og:type`) and **resolve** them into a flat meta-bag with sensible fallbacks. Writes are guarded by
`blog.post.update`; every string override is trimmed (empty → null) and length-capped fail-loud
(`InvalidSeoDataException` 6001). Reads (`for`/`resolve`) are unguarded.

```php
// Write. set() is a FULL replace (an omitted field resets to default/null);
// update() is partial (only the provided keys change).
BlogManager::seo()->set($post, [
    'meta_title'       => 'Hello world — Acme Blog',
    'meta_description' => 'A short, searchy summary.',
    'canonical_url'    => 'https://acme.test/blog/hello-world',
    'noindex'          => false,
    'nofollow'         => false,
    'og_title'         => 'Hello world',          // affects og:title only, never <title>
    'og_description'   => 'Social-card copy.',
    'og_image'         => 'https://acme.test/img/hello.png',
    'og_type'          => 'article',
]);
BlogManager::seo()->update($post, ['noindex' => true]);   // partial

// Read
$raw = BlogManager::seo()->for($post);          // ?PostSeo — the raw stored row (null if unset)
$seo = BlogManager::seo()->resolve($post);      // ResolvedSeo — resolved meta-bag with fallbacks
```

`resolve()` returns a readonly `ResolvedSeo` value object — the resolved bag after the fallback chain
(meta over post title, meta/og description over a derived first-paragraph excerpt, `og_title` over the page
title without changing `<title>`, `og_type` over `config('blog-manager.seo.default_og_type')`):

```php
$seo->title;         // string   — page <title> (meta_title ?? post.title)
$seo->description;   // ?string  — meta_description ?? derived excerpt ?? null
$seo->canonicalUrl;  // ?string  — the override, or null (build your own default)
$seo->noindex;       // bool
$seo->nofollow;      // bool
$seo->ogTitle;       // string   — og_title ?? title
$seo->ogDescription; // ?string  — og_description ?? description
$seo->ogImage;       // ?string
$seo->ogType;        // string   — og_type ?? config('blog-manager.seo.default_og_type', 'article')

$seo->toArray();
// [
//   'title'        => string,
//   'description'  => ?string,
//   'canonicalUrl' => ?string,
//   'robots'       => ['noindex' => bool, 'nofollow' => bool],
//   'og'           => ['title' => string, 'description' => ?string, 'image' => ?string, 'type' => string],
// ]
```

> **The package emits NO tags.** `ResolvedSeo` is scalars only — no HTML, no `<meta>`/`<title>`/JSON-LD/sitemap.
> **You** serialize the DTO into markup in your own view/layer (`<meta name="description" content="...">`,
> `<meta property="og:title" ...>`, robots, canonical). The package owns the *values*; the host owns the *tags*.

### Feed recipe — eager-load to stay N+1-free
`resolve()` reads the post's `seo` row and (only when no meta/og description is set) its **first paragraph**
for the excerpt. When resolving a **list/feed** of posts, eager-load both relations so resolution stays a
constant **2 loads, size-independent** — never a per-post lazy load of the whole block tree:

```php
$posts = Post::query()->with(['seo', 'firstParagraph'])->paginate(15);

foreach ($posts as $post) {
    $meta = BlogManager::seo()->resolve($post);   // no extra queries per post
}
```

## Events
Each mutation dispatches an after-commit event you can listen for: `PostCreated/Updated/Deleted`,
`PostPublished/Unpublished`, `BlockAppended/Updated/Removed`, `BlocksReordered`, `MediaStored/Deleted`,
`PostRevisionCreated`, `PostRestored`, `Category/TagCreated/Updated/Deleted`, `PostCategorized`, `PostTagged`,
`PostSeoUpdated`. The package ships no listeners. See [events.md](events.md).

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
