<?php

declare(strict_types=1);

use Aristonis\BlogManager\Support\AuthorKeyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $posts = $this->table('posts', 'blog_posts');

        Schema::create($this->table('post_revisions', 'blog_post_revisions'), function (Blueprint $table) use ($posts): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('post_id')->constrained($posts)->cascadeOnDelete();
            // Full immutable snapshot of the post attributes + ordered block tree
            // (media referenced by id, never copied). Written once, never mutated.
            $table->json('snapshot');
            $table->string('label')->nullable(); // e.g. 'published', or host-supplied
            // Nullable, unconstrained host author reference (no DB FK — same rule as
            // posts.author_id; the author table belongs to the host). Same
            // host-configured column type as author_id; no standalone index
            // (FR-77 — revisions are read via the composite (post_id, id)).
            AuthorKeyType::apply($table, 'created_by')->nullable();
            $table->timestamps();

            // Composite index for RevisionService::prune's dominant access path
            // (WHERE post_id = ? ORDER BY id DESC LIMIT keep): an index range on
            // post_id plus a reverse scan on the trailing id, no per-group sort.
            // Postgres does not auto-index the constrained() FK column, so index
            // it explicitly (MySQL/InnoDB would, SQLite/Postgres do not).
            $table->index(['post_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('post_revisions', 'blog_post_revisions'));
    }

    private function table(string $key, string $default): string
    {
        $table = config("blog-manager.tables.{$key}", $default);

        return is_string($table) ? $table : $default;
    }
};
