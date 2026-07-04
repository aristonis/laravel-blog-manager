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

        Schema::create($this->table('post_seo', 'blog_post_seo'), function (Blueprint $table) use ($posts): void {
            $table->id();
            // unique(post_id): the 1:1 guarantee AND the Postgres-safe FK index in one
            // (no separate index — Postgres does not auto-index the constrained() FK).
            // ->unique() sits on the column, before ->constrained() (which returns the
            // FK definition), so the unique index lands on post_id.
            $table->foreignId('post_id')->unique()->constrained($posts)->cascadeOnDelete();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('canonical_url', 2048)->nullable(); // URLs can be long; override only
            $table->boolean('noindex')->default(false);
            $table->boolean('nofollow')->default(false);
            $table->string('og_title', 255)->nullable();
            $table->string('og_description', 500)->nullable();
            $table->string('og_image', 2048)->nullable(); // string URL (O-1); host owns the source
            // NO DB default: the resolver owns the default via config (one source of
            // truth — a DB default would shadow config('blog-manager.seo.default_og_type')).
            $table->string('og_type', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('post_seo', 'blog_post_seo'));
    }

    private function table(string $key, string $default): string
    {
        $table = config("blog-manager.tables.{$key}", $default);

        return is_string($table) ? $table : $default;
    }
};
