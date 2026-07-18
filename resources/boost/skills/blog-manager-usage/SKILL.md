---
name: blog-manager-usage
description: Use aristonis/laravel-blog-manager to create posts, append and reorder ordered content blocks (text and media), store media, and render a post. Use when creating, editing, or rendering blog content through the BlogManager facade or the Post/Block/Media services.
---

# Skill — use the package

Create posts, add ordered blocks (text + media), reorder, and render.

## Entry points
- Facade: `Aristonis\BlogManager\Facades\BlogManager` → `posts()`, `blocks()`, `media()`, `render($post)`.
- Or resolve `PostService`, `BlockService`, `MediaManager` from the container directly.

## Recipe
```php
use Aristonis\BlogManager\Facades\BlogManager;

$post = BlogManager::posts()->create(['title' => 'Hello world']); // slug auto-derived, unique

BlogManager::blocks()->append($post, 'heading', ['text' => 'Intro', 'level' => 1]);
BlogManager::blocks()->append($post, 'paragraph', ['format' => 'markdown', 'content' => '**hi**']);

// media is stored first, then referenced by a media block (kind must match)
$image = BlogManager::media()->store($uploadedFile);          // MediaKind::Image
BlogManager::blocks()->append($post, 'image', ['alt' => 'A photo'], $image);

// reorder by passing the full list of block public ids in the new order
$post->refresh();
$ids = $post->blocks->pluck('public_id')->all();
BlogManager::blocks()->reorder($post, array_reverse($ids));

// read back, ordered + sanitized
$rendered = BlogManager::render(BlogManager::posts()->find($post->public_id));
```
