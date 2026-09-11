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
        Schema::create('creator_revenue_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->ulid('source_id');
            $table->foreignUlid('ledger_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('fee_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('fx_rate_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit', 3);
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('net_amount');
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
            $table->unique(['source_type', 'source_id']);
            $table->index(['creator_profile_id', 'occurred_at']);
        });
        Schema::create('creator_payout_settings', function (Blueprint $table): void {
            $table->foreignUlid('creator_profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUlid('payout_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->string('schedule')->default('monthly');
            $table->unsignedBigInteger('minimum_amount')->default(1000);
            $table->string('compliance_state')->default('pending')->index();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('creator_payout_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('unit', 3);
            $table->string('state')->default('draft')->index();
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('payout_count')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->foreignUlid('prepared_by')->constrained('admins')->restrictOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('reason');
            $table->timestampsTz();
            $table->unique(['period_start', 'period_end', 'unit']);
        });
        Schema::create('creator_payouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_payout_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('creator_profile_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payout_method_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('fx_rate_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit', 3);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('state')->default('queued')->index();
            $table->string('idempotency_key')->unique();
            $table->string('provider_reference')->nullable()->unique();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['creator_payout_batch_id', 'creator_profile_id'], 'creator_batch_profile_unique');
        });
        Schema::create('premium_checkouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('premium_plan_id')->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key')->unique();
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('state')->default('initializing')->index();
            $table->text('checkout_url')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_reference']);
        });
        Schema::create('premium_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('premium_plan_id')->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_subscription_id');
            $table->string('state')->default('active')->index();
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end')->index();
            $table->timestampTz('cancel_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_subscription_id']);
            $table->index(['user_id', 'state']);
        });
        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('premium_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('premium_checkout_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('provider_invoice_id');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->string('currency', 3);
            $table->string('state')->index();
            $table->text('receipt_url')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_invoice_id']);
        });
        Schema::create('premium_refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->string('provider_refund_id');
            $table->unsignedBigInteger('amount_minor');
            $table->string('state')->index();
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->unique(['invoice_id', 'provider_refund_id']);
        });
        Schema::create('premium_exclusive_episodes', function (Blueprint $table): void {
            $table->foreignUlid('episode_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('premium_exclusive_episodes');
        Schema::dropIfExists('premium_refunds');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('premium_subscriptions');
        Schema::dropIfExists('premium_checkouts');
        Schema::dropIfExists('creator_payouts');
        Schema::dropIfExists('creator_payout_batches');
        Schema::dropIfExists('creator_payout_settings');
        Schema::dropIfExists('creator_revenue_events');
    }
};
