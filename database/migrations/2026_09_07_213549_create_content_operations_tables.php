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
        Schema::create('episode_chapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('starts_at_seconds');
            $table->string('title', 200);
            $table->text('url')->nullable();
            $table->timestampsTz();
            $table->unique(['episode_id', 'starts_at_seconds']);
        });
        Schema::create('episode_transcripts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->string('language', 35)->nullable();
            $table->string('format', 20);
            $table->text('source_url')->nullable();
            $table->longText('content')->nullable();
            $table->string('state')->default('available')->index();
            $table->timestampsTz();
        });
        Schema::create('downloads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('authorized_until')->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'episode_id', 'device_id']);
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('deduplication_key');
            $table->string('title', 160);
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'deduplication_key']);
            $table->index(['user_id', 'created_at', 'id']);
        });
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('new_episodes')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestampsTz();
        });
        Schema::create('show_metadata_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestampTz('detected_at')->index();
        });
        Schema::create('feed_sync_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->string('state')->index();
            $table->text('resolved_url')->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('new_episode_count')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
        });
        Schema::create('claim_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('show_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->string('decision');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestampsTz();
        });
        Schema::create('moderation_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->ulidMorphs('subject');
            $table->string('action');
            $table->string('reason_code');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['moderation_actions', 'claim_reviews', 'feed_sync_runs', 'show_metadata_changes', 'notification_preferences', 'notifications', 'downloads', 'episode_transcripts', 'episode_chapters'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
