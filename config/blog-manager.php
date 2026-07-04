<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Author model
    |--------------------------------------------------------------------------
    | The host model a post's optional author references. The package never
    | imports the host User model directly; it resolves this class at call
    | time. Leave null to keep posts author-less. Example: App\Models\User::class
    */
    'author_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    | Override to avoid collisions with existing host tables.
    */
    'tables' => [
        'posts' => 'blog_posts',
        'content_blocks' => 'blog_content_blocks',
        'media_items' => 'blog_media_items',
        'post_revisions' => 'blog_post_revisions',
        'post_seo' => 'blog_post_seo',
        'categories' => 'blog_categories',
        'tags' => 'blog_tags',
        'post_category' => 'blog_post_category',
        'post_tag' => 'blog_post_tag',
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxonomy (behavior only — table names live in `tables` above)
    |--------------------------------------------------------------------------
    | Behaviour toggles for categories + tags. Attaching a tag by a name that
    | does not yet exist auto-creates it (folksonomy ergonomics); set this to
    | false to require every tag to pre-exist (an unknown tag name then throws
    | TagNotFoundException). Categories always must pre-exist regardless.
    |
    | NOTE: tag-name matching (find-or-create by name) follows the HOST database
    | collation — MySQL's default *_ci collation treats "PHP" and "php" as the
    | same tag, while SQLite/Postgres default to case-sensitive. The package does
    | not normalize tag-name case. (Tag *public-id* resolution IS case-insensitive
    | — ULIDs are Crockford base32; only name matching defers to the DB.)
    */
    'taxonomy' => [
        'tags' => [
            'auto_create' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO (behavior only — table name lives in `tables` above)
    |--------------------------------------------------------------------------
    | Per-post SEO metadata resolution. `default_og_type` is applied by the
    | resolver when a post has no explicit og_type (the og_type column has no DB
    | default — config is the single source of truth). `excerpt_length` bounds
    | the meta-description fallback derived from the post's first paragraph.
    */
    'seo' => [
        'default_og_type' => 'article',
        'excerpt_length' => 155,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    | The active storage adapter, the disk/path it writes to, how MIME types
    | map to a kind, and the per-kind allow-lists + size caps (bytes).
    */
    'media' => [
        'adapter' => 'filesystem',
        'disk' => 'public',
        'path' => 'blog-media',

        // Matched top-to-bottom; anything unmatched falls back to 'file'.
        'kind_map' => [
            'image/*' => 'image',
            'video/*' => 'video',
        ],

        'allowed_mime' => [
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'video' => ['video/mp4', 'video/webm', 'video/ogg'],

            // No arbitrary file types are allowed by default (v0.1). File blocks
            // stay disabled until the host opts in by listing MIME types here,
            // e.g. 'application/pdf', 'application/zip'.
            'file' => [],
        ],

        // Maximum accepted size per kind, in bytes.
        'max_size' => [
            'image' => 5 * 1024 * 1024,    // 5 MB
            'video' => 100 * 1024 * 1024,  // 100 MB
            'file' => 20 * 1024 * 1024,    // 20 MB
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization (pluggable, allow-all by default)
    |--------------------------------------------------------------------------
    | 'none'  => allow every ability (default, so the backend works on install)
    | 'gate'  => delegate to Laravel Gate / host policies
    | <custom> => a driver registered by the host (e.g. spatie-permission backed)
    |
    | By default (enforce_in_services => false) the services do not check
    | abilities — the host authorizes in its own transport layer. Set
    | enforce_in_services => true to enforce abilities inside the services on
    | every mutation (create/update/delete/publish/unpublish); the services then
    | check the ability with the specific post/block as subject, so per-post /
    | ownership policies apply.
    */
    'authorization' => [
        'driver' => 'none',
        'enforce_in_services' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Revisions
    |--------------------------------------------------------------------------
    | Post revision history. A revision is a full immutable snapshot of a post
    | (attributes + ordered block tree; media referenced by id, never copied).
    */
    'revisions' => [
        // Auto-capture a revision whenever a post is published (freezes what
        // went live). Manual snapshots via RevisionService::snapshot() are
        // always available regardless of this flag.
        'snapshot_on_publish' => true,

        // How many revisions to keep per post: an integer prunes the oldest
        // beyond N after each capture (newest kept). Defaults to a finite 20 so
        // history cannot grow unbounded out of the box; set to null for
        // unlimited retention.
        'keep' => 20,

        // What restore() does when a snapshot references media that was since
        // deleted: 'strict' throws with the missing list; 'lenient' restores
        // the other blocks, drops the missing ones, and returns the list.
        'on_missing_media' => 'strict',
    ],

];
