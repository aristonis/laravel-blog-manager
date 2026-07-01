<?php

declare(strict_types=1);

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
            $table->string('kind'); // image | video | file
            $table->string('mime');
            $table->unsignedBigInteger('size'); // bytes
            $table->string('original_filename');
            $table->string('adapter')->default('filesystem');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->json('meta')->nullable(); // provider-specific reference
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('blog-manager.tables.media_items', 'blog_media_items');

        return is_string($table) ? $table : 'blog_media_items';
    }
};
