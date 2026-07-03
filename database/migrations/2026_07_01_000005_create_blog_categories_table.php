<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('categories', 'blog_categories'), function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            // A curated term with a table-unique name (uniqueness enforced by the
            // service, §2.4) and a table-unique slug (the human-friendly address).
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('categories', 'blog_categories'));
    }

    private function table(string $key, string $default): string
    {
        $table = config("blog-manager.tables.{$key}", $default);

        return is_string($table) ? $table : $default;
    }
};
