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
        Schema::create('push_tokens', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->text('token_encrypted');
            $t->char('token_hash', 64)->unique();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('user_affinities', function (Blueprint $t): void {
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('subject_type');
            $t->ulid('subject_id');
            $t->decimal('score', 8, 5);
            $t->timestampsTz();
            $t->primary(['user_id', 'subject_type', 'subject_id']);
        });
        Schema::create('content_features', function (Blueprint $t): void {
            $t->string('subject_type');
            $t->ulid('subject_id');
            $t->unsignedInteger('version');
            $t->json('features');
            $t->timestampsTz();
            $t->primary(['subject_type', 'subject_id', 'version']);
        });
        Schema::create('ranking_experiments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('state')->default('draft')->index();
            $t->unsignedTinyInteger('rollout_percent')->default(0);
            $t->json('parameters');
            $t->timestampsTz();
        });
        Schema::create('editorial_overrides', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('surface');
            $t->string('subject_type');
            $t->ulid('subject_id');
            $t->integer('position');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $t->text('reason');
            $t->timestampsTz();
            $t->index(['surface', 'starts_at', 'ends_at']);
        });
        Schema::create('referral_fraud_clusters', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('fingerprint_hash', 64)->index();
            $t->unsignedInteger('referral_count');
            $t->string('state')->default('open')->index();
            $t->json('evidence');
            $t->timestampsTz();
        });
        Schema::create('scheduled_exports', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('type');
            $t->json('filters');
            $t->string('schedule');
            $t->string('state')->default('active')->index();
            $t->foreignUlid('admin_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('last_run_at')->nullable();
            $t->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['scheduled_exports', 'referral_fraud_clusters', 'editorial_overrides', 'ranking_experiments', 'content_features', 'user_affinities', 'push_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
