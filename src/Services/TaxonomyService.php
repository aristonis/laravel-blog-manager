<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

use Aristonis\BlogManager\Events\CategoryCreated;
use Aristonis\BlogManager\Events\CategoryDeleted;
use Aristonis\BlogManager\Events\CategoryUpdated;
use Aristonis\BlogManager\Events\TagCreated;
use Aristonis\BlogManager\Events\TagDeleted;
use Aristonis\BlogManager\Events\TagUpdated;
use Aristonis\BlogManager\Exceptions\InvalidTaxonomyDataException;
use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Tag;
use Aristonis\BlogManager\Support\SlugGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Taxonomy term lifecycle for both axes (categories + tags). Owns transactions
 * and dispatches domain events after commit. Categories are curated (unique
 * names); tags are free-form (names may repeat). Slugs are table-unique in each
 * axis and stable across renames — mirroring Post slug behavior via the shared
 * SlugGenerator. The package ships no listeners (D12).
 */
final class TaxonomyService
{
    public function __construct(
        private readonly SlugGenerator $slugs,
    ) {}

    /**
     * Create a curated category. Rejects an empty name and a duplicate name
     * (categories are unique); derives a table-unique slug. CategoryCreated
     * fires after commit.
     */
    public function createCategory(string $name, ?string $slug = null): Category
    {
        $name = $this->requireName($name);
        $this->requireUniqueCategoryName($name);
        $base = $this->baseSlug($slug, $name);

        return DB::transaction(function () use ($name, $base): Category {
            $category = Category::create([
                'name' => $name,
                'slug' => $this->slugs->unique(Category::class, $base, fallback: 'category'),
            ]);

            event(new CategoryCreated($category));

            return $category;
        });
    }

    /**
     * Create a free-form tag. Rejects an empty name; tag names may repeat, so
     * only the slug is uniquified. TagCreated fires after commit.
     */
    public function createTag(string $name, ?string $slug = null): Tag
    {
        $name = $this->requireName($name);
        $base = $this->baseSlug($slug, $name);

        return DB::transaction(function () use ($name, $base): Tag {
            $tag = Tag::create([
                'name' => $name,
                'slug' => $this->slugs->unique(Tag::class, $base, fallback: 'tag'),
            ]);

            event(new TagCreated($tag));

            return $tag;
        });
    }

    /**
     * Rename a category. The slug is stable — changed only when a new one is
     * supplied (then re-uniquified, ignoring this row). Renaming to another
     * category's name is rejected (unique names). CategoryUpdated fires after
     * commit.
     */
    public function renameCategory(Category $category, string $name, ?string $slug = null): Category
    {
        $name = $this->requireName($name);
        $this->requireUniqueCategoryName($name, $category->id);

        return DB::transaction(function () use ($category, $name, $slug): Category {
            $category->name = $name;

            if ($slug !== null) {
                $category->slug = $this->slugs->unique(Category::class, Str::slug($slug), $category->id, 'category');
            }

            $category->save();

            event(new CategoryUpdated($category));

            return $category->refresh();
        });
    }

    /**
     * Rename a tag. Tag names may repeat, so no uniqueness check; the slug is
     * stable unless a new one is supplied. TagUpdated fires after commit.
     */
    public function renameTag(Tag $tag, string $name, ?string $slug = null): Tag
    {
        $name = $this->requireName($name);

        return DB::transaction(function () use ($tag, $name, $slug): Tag {
            $tag->name = $name;

            if ($slug !== null) {
                $tag->slug = $this->slugs->unique(Tag::class, Str::slug($slug), $tag->id, 'tag');
            }

            $tag->save();

            event(new TagUpdated($tag));

            return $tag->refresh();
        });
    }

    /**
     * Delete a category, first detaching every post pivot row so posts survive
     * (a taxonomy op never deletes content). CategoryDeleted fires after commit.
     */
    public function deleteCategory(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $category->posts()->detach();
            $category->delete();

            event(new CategoryDeleted($category));
        });
    }

    /**
     * Delete a tag, first detaching every post pivot row so posts survive.
     * TagDeleted fires after commit.
     */
    public function deleteTag(Tag $tag): void
    {
        DB::transaction(function () use ($tag): void {
            $tag->posts()->detach();
            $tag->delete();

            event(new TagDeleted($tag));
        });
    }

    /** Trim and require a non-empty term name (fail-loud). */
    private function requireName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidTaxonomyDataException(
                'A taxonomy term requires a non-empty name.',
                ['field' => 'name'],
            );
        }

        return $trimmed;
    }

    /**
     * Reject a category name already used by another category (unique names).
     * $ignoreId excludes the row being renamed so a no-op rename is allowed.
     */
    private function requireUniqueCategoryName(string $name, ?int $ignoreId = null): void
    {
        $exists = Category::query()
            ->where('name', $name)
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new InvalidTaxonomyDataException(
                "A category named [{$name}] already exists.",
                ['field' => 'name', 'name' => $name],
            );
        }
    }

    private function baseSlug(?string $slug, string $name): string
    {
        return is_string($slug) && $slug !== '' ? Str::slug($slug) : Str::slug($name);
    }
}
