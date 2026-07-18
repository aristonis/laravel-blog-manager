# Configuration

Publish the config, then edit `config/blog-manager.php`:

```bash
php artisan vendor:publish --tag=blog-manager-config
```

## Reference

| Key | Default | Purpose |
|-----|---------|---------|
| `author_model` | `null` | Host model a post may reference as author (nullable, decoupled). e.g. `App\Models\User::class`. |
| `author_key_type` | `bigint` | Column type for a post's author reference — one of `bigint` (default, numeric host keys) · `uuid` · `ulid` (string host keys). Declared **once** here and applied to **both** `blog_posts.author_id` and `blog_post_revisions.created_by` at migrate time; no DB foreign key either way (the author table belongs to the host). An unknown value fails loud (see Notes). |
| `tables.posts` | `blog_posts` | Posts table name. |
| `tables.content_blocks` | `blog_content_blocks` | Blocks table name. |
| `tables.media_items` | `blog_media_items` | Media table name. |
| `tables.post_revisions` | `blog_post_revisions` | Revisions table name. |
| `tables.categories` | `blog_categories` | Categories table name. |
| `tables.tags` | `blog_tags` | Tags table name. |
| `tables.post_category` | `blog_post_category` | Post↔category pivot table name. |
| `tables.post_tag` | `blog_post_tag` | Post↔tag pivot table name. |
| `tables.post_seo` | `blog_post_seo` | Per-post SEO metadata table (1:1 with a post). |
| `seo.default_og_type` | `article` | `og:type` the resolver falls back to when a post sets no `og_type`. |
| `seo.excerpt_length` | `155` | Max character length of the auto-derived meta/og description excerpt (mb-safe, word-boundary). |
| `media.adapter` | `filesystem` | Active storage driver key (see [../resources/boost/skills/blog-manager-add-media-adapter/SKILL.md](../resources/boost/skills/blog-manager-add-media-adapter/SKILL.md)). |
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
| `revisions.keep` | `20` | Revisions kept per post; an integer prunes the oldest beyond N, `null` = unlimited. |
| `revisions.on_missing_media` | `strict` | Restore with deleted media: `strict` throws with the missing list; `lenient` drops those blocks and restores the rest. |
| `taxonomy.tags.auto_create` | `true` | Attaching a tag by an unknown name creates it; set `false` to require tags to pre-exist (then an unknown name throws `TagNotFoundException`). |

## Notes
- **`author_key_type` fails loud on an unknown value** — it is validated in **two** places: at application bootstrap (the service provider's `boot()`) and again at migrate time, before any table is created. A value outside `bigint` · `uuid` · `ulid` throws `InvalidArgumentException` (`Invalid blog-manager.author_key_type [<value>]; allowed: bigint, uuid, ulid.`) — there is no silent fallback to `bigint`, and a bad config leaves the database in its pre-migration state (no partial table). Both author columns always share this one type; they never diverge.
- **`author_key_type` is a one-way door** — the type is read **only at migrate time** and baked into the column. Changing it **after the first migrate has NO effect** on an existing table; reshaping the column requires the host's own alter + data-conversion migration. Pick it before the first migrate.
- **Secure file default:** `media.allowed_mime.file` ships empty; file blocks stay unusable until you list MIME types.
- Config values are read at call time — safe to override per environment.
- Do not put closures in the config (breaks `config:cache`).
