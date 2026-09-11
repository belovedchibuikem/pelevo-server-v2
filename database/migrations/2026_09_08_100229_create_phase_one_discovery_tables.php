<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestampsTz();
        });
        Schema::create('category_show', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'show_id']);
        });
        Schema::create('show_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->string('state')->default('published')->index();
            $table->timestampsTz();
            $table->unique(['show_id', 'user_id']);
            $table->index(['show_id', 'state', 'created_at', 'id'], 'show_reviews_cursor_index');
        });
        Schema::create('search_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('query', 100);
            $table->char('query_hash', 64);
            $table->timestampTz('searched_at')->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'query_hash']);
            $table->index(['user_id', 'searched_at', 'id']);
        });
        Schema::create('editorial_playlists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('artwork_url')->nullable();
            $table->boolean('published')->default(false)->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });
        Schema::create('editorial_playlist_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('editorial_playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestampsTz();
            $table->unique(['editorial_playlist_id', 'episode_id']);
            $table->unique(['editorial_playlist_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('editorial_playlist_items');
        Schema::dropIfExists('editorial_playlists');
        Schema::dropIfExists('search_history');
        Schema::dropIfExists('show_reviews');
        Schema::dropIfExists('category_show');
        Schema::dropIfExists('categories');
    }
};
