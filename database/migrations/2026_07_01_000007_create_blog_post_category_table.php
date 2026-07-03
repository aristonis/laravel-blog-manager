<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $posts = $this->table('posts', 'blog_posts');
        $categories = $this->table('categories', 'blog_categories');

        Schema::create($this->table('post_category', 'blog_post_category'), function (Blueprint $table) use ($posts, $categories): void {
            // Pivot holds only the FK pair — no per-post ordering, no payload (§2.2).
            // The composite unique makes membership idempotent at the DB (a duplicate
            // pair is rejected). Deleting either side clears the association (a taxonomy
            // op never deletes content).
            $table->foreignId('post_id')->constrained($posts)->cascadeOnDelete();
            $table->foreignId('category_id')->constrained($categories)->cascadeOnDelete();
            $table->unique(['post_id', 'category_id']);
            // postsByCategory() filters on category_id; the unique above leads with
            // post_id and Postgres does not auto-index FK columns, so index it explicitly
            // (leading with category_id, covering post_id for the join).
            $table->index(['category_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('post_category', 'blog_post_category'));
    }

    private function table(string $key, string $default): string
    {
        $table = config("blog-manager.tables.{$key}", $default);

        return is_string($table) ? $table : $default;
    }
};
