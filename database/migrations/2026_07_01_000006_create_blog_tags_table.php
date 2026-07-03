<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('tags', 'blog_tags'), function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            // A free-form term: names may repeat (folksonomy), only the slug is
            // table-unique (auto-suffixed on collision by the service, §2.4). Name is
            // indexed (not unique) — resolveTag() looks tags up by name on every
            // auto-create attach, the default path.
            $table->string('name')->index();
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('tags', 'blog_tags'));
    }

    private function table(string $key, string $default): string
    {
        $table = config("blog-manager.tables.{$key}", $default);

        return is_string($table) ? $table : $default;
    }
};
