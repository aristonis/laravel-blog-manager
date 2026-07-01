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
            $table->unsignedInteger('position'); // unique + contiguous per post (enforced in BlockService)
            $table->json('data')->nullable(); // type payload
            $table->foreignId('media_item_id')->nullable()->constrained($media)->nullOnDelete();
            $table->index(['post_id', 'position']);
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
