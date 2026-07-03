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
        $tags = $this->table('tags', 'blog_tags');

        Schema::create($this->table('post_tag', 'blog_post_tag'), function (Blueprint $table) use ($posts, $tags): void {
            // Pivot holds only the FK pair — no per-post ordering, no payload (§2.2).
            // constrained() indexes each FK; the composite unique makes membership
            // idempotent at the DB (a duplicate pair is rejected). Deleting either
            // side clears the association (a taxonomy op never deletes content).
            $table->foreignId('post_id')->constrained($posts)->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained($tags)->cascadeOnDelete();
            $table->unique(['post_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('post_tag', 'blog_post_tag'));
    }

    private function table(string $key, string $default): string
    {
        $table = config("blog-manager.tables.{$key}", $default);

        return is_string($table) ? $table : $default;
    }
};
