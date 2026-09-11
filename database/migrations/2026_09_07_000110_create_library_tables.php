<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['user_id', 'updated_at', 'id']);
        });
        Schema::create('playlist_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestampsTz();
            $table->unique(['playlist_id', 'episode_id']);
            $table->unique(['playlist_id', 'position']);
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['user_id', 'updated_at', 'id']);
        });
        Schema::create('collection_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('collection_id')->constrained()->cascadeOnDelete();
            $table->string('collectable_type', 30);
            $table->ulid('collectable_id');
            $table->timestampsTz();
            $table->unique(['collection_id', 'collectable_type', 'collectable_id'], 'collection_items_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('playlist_items');
        Schema::dropIfExists('playlists');
    }
};
