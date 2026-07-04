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
        $media = $this->table('media_items', 'blog_media_items');

        Schema::create($this->table('content_blocks', 'blog_content_blocks'), function (Blueprint $table) use ($posts, $media): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('post_id')->constrained($posts)->cascadeOnDelete();
            $table->string('type'); // registry key
            $table->unsignedInteger('position'); // contiguous 0..n-1 per post (kept by BlockService)
            $table->json('data')->nullable(); // type payload
            // Postgres does not auto-index the constrained() FK column, so index it
            // explicitly: serves MediaManager::orphaned()'s anti-join scan and the
            // nullOnDelete cascade on every media delete (MySQL/InnoDB would, Postgres does not).
            $table->foreignId('media_item_id')->nullable()->index()->constrained($media)->nullOnDelete();
            // Uniqueness enforced at the DB; BlockService reorders in two phases to
            // avoid a transient collision inside the transaction.
            $table->unique(['post_id', 'position']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('content_blocks', 'blog_content_blocks'));
    }

    private function table(string $key, string $default): string
    {
        $table = config("blog-manager.tables.{$key}", $default);

        return is_string($table) ? $table : $default;
    }
};
