# Configuration

Publish the config, then edit `config/blog-manager.php`:

```bash
php artisan vendor:publish --tag=blog-manager-config
```

## Reference

| Key | Default | Purpose |
|-----|---------|---------|
| `author_model` | `null` | Host model a post may reference as author (nullable, decoupled). e.g. `App\Models\User::class`. |
| `tables.posts` | `blog_posts` | Posts table name. |
| `tables.content_blocks` | `blog_content_blocks` | Blocks table name. |
| `tables.media_items` | `blog_media_items` | Media table name. |
| `tables.post_revisions` | `blog_post_revisions` | Revisions table name. |
| `tables.categories` | `blog_categories` | Categories table name. |
| `tables.tags` | `blog_tags` | Tags table name. |
| `tables.post_category` | `blog_post_category` | Post↔category pivot table name. |
| `tables.post_tag` | `blog_post_tag` | Post↔tag pivot table name. |
| `media.adapter` | `filesystem` | Active storage driver key (see [../.ai/skills/add-media-adapter.md](../.ai/skills/add-media-adapter.md)). |
| `media.disk` | `public` | Filesystem disk the default adapter writes to. |
| `media.path` | `blog-media` | Directory within the disk. |
| `media.kind_map` | `image/* => image, video/* => video` | MIME → kind patterns; unmatched falls back to `file`. |
| `media.allowed_mime.image` | jpeg/png/gif/webp | Allowed image MIME types. |
| `media.allowed_mime.video` | mp4/webm/ogg | Allowed video MIME types. |
| `media.allowed_mime.file` | `[]` (**empty**) | **No file types allowed by default** — opt in explicitly. |
| `media.max_size.{image,video,file}` | 5 / 100 / 20 MB | Per-kind size caps (bytes). |
| `authorization.driver` | `none` | `none` (allow-all) · `gate` · a custom driver key. |
| `authorization.enforce_in_services` | `false` | Enforce abilities inside the services on every mutation. Default `false` — the host authorizes in its own transport layer. |
| `revisions.snapshot_on_publish` | `true` | Auto-capture a revision whenever a post is published. |
| `revisions.keep` | `null` | Revisions kept per post; `null` = unlimited, an integer prunes the oldest beyond N. |
| `revisions.on_missing_media` | `strict` | Restore with deleted media: `strict` throws with the missing list; `lenient` drops those blocks and restores the rest. |
| `taxonomy.tags.auto_create` | `true` | Attaching a tag by an unknown name creates it; set `false` to require tags to pre-exist (then an unknown name throws `TagNotFoundException`). |

## Notes
- **Secure file default:** `media.allowed_mime.file` ships empty; file blocks stay unusable until you list MIME types.
- Config values are read at call time — safe to override per environment.
- Do not put closures in the config (breaks `config:cache`).
