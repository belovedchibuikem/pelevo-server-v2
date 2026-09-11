<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_profiles', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('display_name');
            $t->string('status')->default('active')->index();
            $t->timestampsTz();
        });
        Schema::create('show_claims', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('creator_profile_id')->constrained()->cascadeOnDelete();
            $t->string('method');
            $t->string('state')->default('pending')->index();
            $t->char('challenge_hash', 64)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestampTz('expires_at');
            $t->timestampTz('verified_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestampsTz();
            $t->index(['show_id', 'state']);
        });
        Schema::create('verified_show_claims', function (Blueprint $t): void {
            $t->foreignUlid('show_id')->primary()->constrained()->cascadeOnDelete();
            $t->foreignUlid('show_claim_id')->unique()->constrained()->cascadeOnDelete();
            $t->timestampsTz();
        });
        Schema::create('comments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->ulidMorphs('commentable');
            // Defer self-FK: PostgreSQL rejects referencing comments(id) while CREATE is still open.
            $t->ulid('parent_id')->nullable()->index();
            $t->string('body', 400);
            $t->boolean('is_pinned')->default(false);
            $t->timestampTz('hidden_at')->nullable();
            $t->timestampTz('edited_at')->nullable();
            $t->timestampsTz();
            $t->index(['commentable_type', 'commentable_id', 'created_at']);
        });
        Schema::table('comments', function (Blueprint $t): void {
            $t->foreign('parent_id')->references('id')->on('comments')->cascadeOnDelete();
        });
        Schema::create('content_reports', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporter_id')->constrained('users')->cascadeOnDelete();
            $t->ulidMorphs('reportable');
            $t->string('reason');
            $t->text('details')->nullable();
            $t->string('state')->default('open')->index();
            $t->timestampsTz();
        });
        Schema::create('reels', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('creator_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('show_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('episode_id')->nullable()->constrained()->nullOnDelete();
            $t->string('caption', 400)->nullable();
            $t->string('state')->default('draft')->index();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->text('media_url')->nullable();
            $t->timestampTz('published_at')->nullable()->index();
            $t->timestampsTz();
        });
        Schema::create('financial_accounts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->nullableUlidMorphs('owner');
            $t->string('type');
            $t->string('unit', 3);
            $t->bigInteger('balance')->default(0);
            $t->timestampsTz();
            $t->unique(['owner_type', 'owner_id', 'type', 'unit']);
        });
        Schema::create('ledger_transactions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('reference')->unique();
            $t->string('event_type')->index();
            $t->string('idempotency_key')->unique();
            $t->ulid('reverses_id')->nullable()->unique();
            $t->json('metadata')->nullable();
            $t->timestampsTz();
        });
        Schema::create('ledger_entries', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('ledger_transaction_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('financial_account_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount');
            $t->string('unit', 3);
            $t->timestampsTz();
            $t->index(['financial_account_id', 'created_at']);
        });
        Schema::create('gift_types', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('slug')->unique();
            $t->string('name');
            $t->unsignedBigInteger('coins');
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('gifts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('sender_id')->constrained('users');
            $t->foreignUlid('creator_profile_id')->constrained();
            $t->foreignUlid('gift_type_id')->constrained();
            $t->foreignUlid('ledger_transaction_id')->unique()->constrained();
            $t->text('message')->nullable();
            $t->timestampsTz();
        });
        Schema::create('earn_sessions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $t->string('state')->default('active')->index();
            $t->unsignedInteger('verified_seconds')->default(0);
            $t->unsignedInteger('last_position')->default(0);
            $t->unsignedInteger('last_sequence')->default(0);
            $t->unsignedTinyInteger('expected_award');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('earn_awards', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('earn_session_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignUlid('ledger_transaction_id')->unique()->constrained();
            $t->unsignedTinyInteger('coins');
            $t->timestampTz('locked_until')->index();
            $t->timestampsTz();
        });
        Schema::create('payout_methods', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->nullableUlidMorphs('owner');
            $t->string('provider');
            $t->text('destination_encrypted');
            $t->string('destination_last_four', 4);
            $t->timestampTz('verified_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('withdrawals', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained();
            $t->foreignUlid('payout_method_id')->constrained();
            $t->foreignUlid('ledger_transaction_id')->unique()->constrained();
            $t->unsignedBigInteger('coins');
            $t->string('state')->default('queued')->index();
            $t->string('idempotency_key')->unique();
            $t->timestampsTz();
        });
        Schema::create('premium_plans', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('slug')->unique();
            $t->string('name');
            $t->unsignedBigInteger('price_minor');
            $t->string('currency', 3);
            $t->string('interval');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
        });
        Schema::create('premium_entitlements', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('premium_plan_id')->constrained();
            $t->string('provider_reference')->unique();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at')->nullable()->index();
            $t->string('state')->default('active');
            $t->timestampsTz();
        });
        Schema::create('provider_webhook_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('provider');
            $t->string('provider_event_id');
            $t->char('payload_hash', 64);
            $t->json('payload');
            $t->string('state')->default('received')->index();
            $t->timestampTz('processed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['provider', 'provider_event_id']);
        });
        Schema::create('sync_changes', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $t->string('resource_type');
            $t->ulid('resource_id');
            $t->string('operation');
            $t->unsignedBigInteger('version');
            $t->json('payload');
            $t->timestampsTz();
            $t->index(['user_id', 'created_at', 'id']);
        });
        Schema::create('referrals', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('referrer_id')->constrained('users');
            $t->foreignUlid('referred_id')->unique()->constrained('users');
            $t->string('code')->unique();
            $t->string('state')->default('pending')->index();
            $t->timestampTz('qualified_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('ai_jobs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('state')->default('queued')->index();
            $t->json('input');
            $t->json('output')->nullable();
            $t->unsignedBigInteger('cost_microusd')->default(0);
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['ai_jobs', 'referrals', 'sync_changes', 'provider_webhook_events', 'premium_entitlements', 'premium_plans', 'withdrawals', 'payout_methods', 'earn_awards', 'earn_sessions', 'gifts', 'gift_types', 'ledger_entries', 'ledger_transactions', 'financial_accounts', 'reels', 'content_reports', 'comments', 'verified_show_claims', 'show_claims', 'creator_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
