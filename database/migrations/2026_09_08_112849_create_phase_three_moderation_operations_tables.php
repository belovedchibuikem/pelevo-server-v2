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
        Schema::create('reel_monetization_profiles', function (Blueprint $table): void {
            $table->foreignUlid('creator_profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('eligible')->default(false)->index();
            $table->boolean('opted_in')->default(false)->index();
            $table->unsignedBigInteger('qualified_views')->default(0);
            $table->unsignedBigInteger('followers')->default(0);
            $table->timestampTz('evaluated_at')->nullable();
            $table->timestampTz('opted_in_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('appeals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->ulidMorphs('subject');
            $table->text('reason');
            $table->string('state')->default('open')->index();
            $table->foreignUlid('decided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'subject_type', 'subject_id']);
        });
        Schema::create('user_sanctions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('state')->default('active')->index();
            $table->text('reason');
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'state', 'expires_at']);
        });
        Schema::create('live_health_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('live_session_id')->constrained()->cascadeOnDelete();
            $table->string('state')->index();
            $table->unsignedInteger('bitrate_kbps')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('dropped_frames')->default(0);
            $table->json('metrics')->nullable();
            $table->timestampTz('recorded_at')->index();
            $table->index(['live_session_id', 'recorded_at']);
        });
        Schema::create('live_takedowns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->string('previous_state');
            $table->timestampsTz();
        });
        Schema::table('content_reports', function (Blueprint $table): void {
            $table->foreignUlid('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('resolution')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->unique(['reporter_id', 'reportable_type', 'reportable_id'], 'reporter_target_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_reports', function (Blueprint $table): void {
            $table->dropUnique('reporter_target_unique');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolution', 'resolution_reason', 'resolved_at']);
        });
        Schema::dropIfExists('live_takedowns');
        Schema::dropIfExists('live_health_snapshots');
        Schema::dropIfExists('user_sanctions');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('reel_monetization_profiles');
    }
};
