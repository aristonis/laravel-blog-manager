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
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            // Nullable, unconstrained author reference to the host-configured model
            // (no DB FK: the author table belongs to the host and may differ per app).
            // Column type (bigint|uuid|ulid) is host-configured via author_key_type.
            AuthorKeyType::apply($table, 'author_id')->nullable()->index();
            // Lifecycle: draft by default. Visibility is computed from status +
            // published_at (published AND published_at <= now); no 'scheduled' state.
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            // Composite index for the dominant "published + recency" access path
            // (Post::scopePublished filters status then constrains/orders by
            // published_at). It also serves a status-only lookup via its leading
            // prefix, so `status` carries no separate standalone index (M6).
            // `published_at` keeps its own index above: it is NOT a prefix of this
            // composite, so a published_at-only filter still needs it.
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('blog-manager.tables.posts', 'blog_posts');

        return is_string($table) ? $table : 'blog_posts';
    }
};
