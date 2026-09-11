<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('rss_url');
            $table->char('rss_url_hash', 64)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('artwork_url')->nullable();
            $table->string('author')->nullable();
            $table->string('language', 35)->nullable();
            $table->string('explicit')->default('unknown');
            $table->string('status')->default('active')->index();
            $table->timestampsTz();
        });
        Schema::create('show_external_ids', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->unique(['provider', 'external_id']);
        });
        Schema::create('show_feed_states', function (Blueprint $table): void {
            $table->foreignUlid('show_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampTz('next_poll_at')->nullable()->index();
            $table->string('state')->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });
        Schema::create('episodes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->string('guid');
            $table->string('external_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('audio_url');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestampTz('published_at')->nullable()->index();
            $table->string('availability')->default('available')->index();
            $table->timestampsTz();
            $table->unique(['show_id', 'guid']);
            $table->index(['show_id', 'published_at', 'id']);
        });
        Schema::create('follows', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->boolean('notifications_enabled')->default(true);
            $table->timestampsTz();
            $table->primary(['user_id', 'show_id']);
        });
        Schema::create('show_ratings', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestampsTz();
            $table->primary(['user_id', 'show_id']);
        });
        Schema::create('playback_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->boolean('completed')->default(false);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['user_id', 'episode_id']);
            $table->index(['user_id', 'updated_at', 'id']);
        });
        Schema::create('queues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
        });
        Schema::create('queue_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('queue_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestampsTz();
            $table->unique(['queue_id', 'episode_id']);
            $table->unique(['queue_id', 'position']);
        });
        Schema::create('episode_saves', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['user_id', 'episode_id']);
        });
    }

    public function down(): void
    {
        foreach (['episode_saves', 'queue_items', 'queues', 'playback_progress', 'show_ratings', 'follows', 'episodes', 'show_feed_states', 'show_external_ids', 'shows'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
