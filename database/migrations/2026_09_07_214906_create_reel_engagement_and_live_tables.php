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
        Schema::create('comment_likes', function (Blueprint $table): void {
            $table->foreignUlid('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['comment_id', 'user_id']);
        });
        Schema::create('reel_engagements', function (Blueprint $table): void {
            $table->foreignUlid('reel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('liked')->default(false);
            $table->boolean('saved')->default(false);
            $table->boolean('not_interested')->default(false);
            $table->timestampsTz();
            $table->primary(['reel_id', 'user_id']);
        });
        Schema::create('reel_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_id');
            $table->unsignedInteger('watched_ms')->default(0);
            $table->boolean('qualified')->default(false);
            $table->timestampTz('qualified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['reel_id', 'user_id', 'session_id'], 'reel_view_session_unique');
            $table->index(['reel_id', 'qualified', 'created_at'], 'reel_qualified_views_index');
        });
        Schema::create('live_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('show_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('state')->default('scheduled')->index();
            $table->timestampTz('scheduled_at')->nullable()->index();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('viewer_count')->default(0);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_sessions');
        Schema::dropIfExists('reel_views');
        Schema::dropIfExists('reel_engagements');
        Schema::dropIfExists('comment_likes');
    }
};
