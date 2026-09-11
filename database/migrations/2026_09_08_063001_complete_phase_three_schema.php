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
        Schema::create('comment_pins', function (Blueprint $table): void {
            $table->ulidMorphs('commentable');
            $table->foreignUlid('comment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('pinned_by')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['commentable_type', 'commentable_id'], 'comment_pins_subject_primary');
        });
        Schema::create('reel_episode_links', function (Blueprint $table): void {
            $table->foreignUlid('reel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['reel_id', 'episode_id']);
        });
        Schema::create('reel_view_credits', function (Blueprint $table): void {
            $table->foreignUlid('reel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('window_key', 32);
            $table->unsignedTinyInteger('ordinal');
            $table->foreignUlid('reel_view_id')->unique()->constrained('reel_views')->cascadeOnDelete();
            $table->timestampTz('created_at');
            $table->primary(['reel_id', 'user_id', 'window_key', 'ordinal'], 'reel_view_credit_primary');
        });
        Schema::create('reel_processing_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reel_id')->constrained()->cascadeOnDelete();
            $table->string('state')->index();
            $table->json('details')->nullable();
            $table->timestampTz('created_at');
            $table->index(['reel_id', 'created_at', 'id'], 'reel_processing_timeline_index');
        });
        Schema::table('reel_media', function (Blueprint $table): void {
            $table->string('transcoded_path', 500)->nullable()->after('processing_state');
            $table->json('safety_results')->nullable()->after('thumbnail_path');
        });
        Schema::create('live_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('live_session_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->json('payload')->nullable();
            $table->timestampTz('created_at');
            $table->index(['live_session_id', 'created_at', 'id'], 'live_event_timeline_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_events');
        Schema::table('reel_media', function (Blueprint $table): void {
            $table->dropColumn(['transcoded_path', 'safety_results']);
        });
        Schema::dropIfExists('reel_processing_events');
        Schema::dropIfExists('reel_view_credits');
        Schema::dropIfExists('reel_episode_links');
        Schema::dropIfExists('comment_pins');
    }
};
