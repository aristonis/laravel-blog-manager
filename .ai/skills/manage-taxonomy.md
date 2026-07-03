# Skill — Manage taxonomy (categories & tags)

How to classify posts along two independent axes and retrieve posts by classification. Core-only — call the
`TaxonomyService` (via the `BlogManager` facade or the container). No HTTP layer.

## Model
- Two separate models, not one polymorphic term: **`Category`** (curated, **unique name**, must pre-exist) and
  **`Tag`** (free-form, names may repeat, **auto-created on attach** by default).
- Dual identity: internal PK is never exposed; external id is an opaque ULID (`public_id`), plus a **unique slug**
  per term table (category and tag slugs are separate namespaces). Renaming a term does **not** change its slug.
- Post↔term is many-to-many via two pivots (`unique(post_id, term_id)`, so attach is idempotent at the DB).
  Membership is **direct only** — no descendant rollup.

## Manage the term catalog (guarded by `blog.taxonomy.manage`)
```php
BlogManager::taxonomy()->createCategory($name, $slug = null);  // unique name; InvalidTaxonomyDataException on dup/empty
BlogManager::taxonomy()->createTag($name, $slug = null);       // free-form; empty name → InvalidTaxonomyDataException
BlogManager::taxonomy()->renameCategory($category, $name, $slug = null); // slug stable unless $slug given
BlogManager::taxonomy()->renameTag($tag, $name, $slug = null);
BlogManager::taxonomy()->deleteCategory($category);            // detaches pivots; posts survive
BlogManager::taxonomy()->deleteTag($tag);
```
- Each dispatches its event after commit: `Category/Tag Created/Updated/Deleted`.
- Delete is **non-destructive to posts** — it detaches the pivot rows in one transaction, then removes the term.

## Attach / detach a post's terms (guarded by `blog.post.update`)
```php
BlogManager::taxonomy()->categorize($post, $categories, sync: false); // add (idempotent)
BlogManager::taxonomy()->syncCategories($post, $categories);          // replace the whole set
BlogManager::taxonomy()->uncategorize($post, $categories);
BlogManager::taxonomy()->tag($post, $tags, sync: false);
BlogManager::taxonomy()->syncTags($post, $tags);
BlogManager::taxonomy()->untag($post, $tags);
```
- Each dispatches one delta event per op: `PostCategorized($post, $added, $removed)` / `PostTagged(...)` — the
  arrays carry the term **models** actually attached/detached (an already-attached or unattached term is a no-op).
- **Input resolution.** Categories: a `Category` model **or** its public-id string — must pre-exist, unknown →
  `CategoryNotFoundException`. Tags: a `Tag` model, a **ULID-shaped** public-id string (unknown →
  `TagNotFoundException`), **or a name** — resolved to an existing tag or **auto-created** when
  `taxonomy.tags.auto_create` is on (else `TagNotFoundException`).

## Read (unguarded — authorize post access yourself)
```php
BlogManager::taxonomy()->for($post);            // ['categories' => Collection, 'tags' => Collection]
BlogManager::taxonomy()->categories();          // Collection, name-ordered (flat)
BlogManager::taxonomy()->tags();                // Collection, name-ordered
BlogManager::taxonomy()->postsByCategory($category, perPage: 15, onlyPublished: false); // LengthAwarePaginator, newest-first
BlogManager::taxonomy()->postsByTag($tag, perPage: 15, onlyPublished: false);
BlogManager::taxonomy()->getCategory($idOrSlug); // by public id OR slug; miss → CategoryNotFoundException
BlogManager::taxonomy()->getTag($idOrSlug);      // by public id OR slug; miss → TagNotFoundException
```
- `postsBy*` returns **direct members only** (via the pivot), newest-first; pass `onlyPublished: true` to filter
  through `Post::scopePublished` (Published AND `published_at <= now`) so drafts/scheduled posts don't leak.
- Both `postsBy*` and `for()` are query-count-bounded (2 queries each) regardless of result/term count.

## Authorization
- **Term catalog** (`create/rename/delete` category & tag) → `blog.taxonomy.manage`, checked **resource-less**
  (a Gate callback gets `($user)` only — no model).
- **Attach/detach** a post's terms → `blog.post.update` **on the post** (callback gets `($user, Post $post)`).
- **Auto-create while tagging** (`tag($post, ['new-name'])`) rides on `blog.post.update` only — an editor who can
  update a post can coin free-form tags **without** `blog.taxonomy.manage`. Direct `createTag`/`createCategory`
  still require `blog.taxonomy.manage`.
- All guards apply only when `authorization.enforce_in_services = true` (otherwise the host authorizes upstream).

## Hard rules
- Categories are curated (unique names, pre-exist); tags are free-form (repeatable, auto-created). Don't blur them.
- A taxonomy op never deletes post content — deleting a term only detaches pivots.
- Transactions live in the service; a failed sync rolls back whole (no partial pivot change, no event).
- A new term is a create call, never an edit to the service engine (OCP).

## Host responsibilities (the package cannot enforce these)
- **Authorize post access before reads** — `for()`/`postsBy*`/`get*` are unguarded at the service layer, like
  `PostService::find()`/`paginate()`. Only pass terms/posts the caller may see.
- **A host writing one Gate policy for both abilities must not assume a model is always passed** —
  `blog.taxonomy.manage` is resource-less; `blog.post.update` carries the post.
- **Validate term ownership in a multi-tenant host** — terms are global, first-class records with no built-in
  tenant scope; scope them yourself before attach.
