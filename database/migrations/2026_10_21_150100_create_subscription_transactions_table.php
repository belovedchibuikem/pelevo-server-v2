<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('premium_subscription_id')->constrained()->restrictOnDelete();
            $table->string('store_platform', 50);
            $table->string('store_event_id', 255);
            $table->string('event_type', 64);
            $table->string('product_id', 100);
            $table->char('billing_country_code', 2);
            $table->char('billing_currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('is_intro_offer')->default(false);
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('raw_payload');
            } else {
                $table->json('raw_payload');
            }
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['store_platform', 'store_event_id'], 'subscription_transactions_event_uq');
            $table->index(['premium_subscription_id', 'created_at'], 'subscription_transactions_sub_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE subscription_transactions
  ADD CONSTRAINT subscription_transactions_event_chk
  CHECK (event_type IN (
    'INITIAL_PURCHASE',
    'RENEWAL',
    'DID_FAIL_TO_RENEW',
    'CANCELLATION',
    'EXPIRATION'
  ))
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
    }
};
