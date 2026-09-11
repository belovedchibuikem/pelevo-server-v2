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
        Schema::create('coin_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('store');
            $table->string('product_id');
            $table->unsignedBigInteger('coins');
            $table->string('unit', 3)->default('PCN');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['store', 'product_id']);
        });
        Schema::create('iap_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('coin_product_id')->constrained();
            $table->foreignUlid('ledger_transaction_id')->nullable()->unique()->constrained();
            $table->string('store');
            $table->string('original_transaction_id');
            $table->char('receipt_hash', 64);
            $table->string('state')->default('received')->index();
            $table->json('verification_payload')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['store', 'original_transaction_id']);
            $table->unique(['user_id', 'receipt_hash']);
        });
        Schema::create('earn_heartbeats', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('earn_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('position');
            $table->unsignedTinyInteger('elapsed_seconds');
            $table->decimal('playback_rate', 4, 2);
            $table->boolean('foreground');
            $table->boolean('audio_active');
            $table->char('integrity_token_hash', 64);
            $table->char('audio_fingerprint_hash', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();
            $table->unique(['earn_session_id', 'sequence']);
        });
        Schema::table('earn_sessions', function (Blueprint $table): void {
            $table->string('risk_state')->default('clear')->index();
            $table->json('eligibility_snapshot')->nullable();
            $table->string('ip_address', 45)->nullable();
        });
        Schema::table('payout_methods', function (Blueprint $table): void {
            $table->string('kind')->default('bank');
            $table->string('label')->nullable();
            $table->string('verification_reference')->nullable();
        });
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->string('provider_reference')->nullable()->unique();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('processed_at')->nullable();
        });
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('business_date')->unique();
            $table->string('state')->default('running')->index();
            $table->unsignedInteger('checked_count')->default(0);
            $table->unsignedInteger('drift_count')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('reconciliation_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reconciliation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('financial_account_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('projected_balance');
            $table->bigInteger('ledger_balance');
            $table->bigInteger('difference');
            $table->string('state')->default('open')->index();
            $table->timestampsTz();
            $table->unique(['reconciliation_run_id', 'financial_account_id'], 'recon_run_account_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('reconciliation_runs');
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropColumn(['provider_reference', 'failure_reason', 'processed_at']);
        });
        Schema::table('payout_methods', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'label', 'verification_reference']);
        });
        Schema::table('earn_sessions', function (Blueprint $table): void {
            $table->dropColumn(['risk_state', 'eligibility_snapshot', 'ip_address']);
        });
        Schema::dropIfExists('earn_heartbeats');
        Schema::dropIfExists('iap_receipts');
        Schema::dropIfExists('coin_products');
    }
};
