<?php

use App\Services\Finance\CoinEconomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_economy_regimes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 40);
            $table->string('name');
            $table->unsignedInteger('store_fee_basis_points');
            $table->unsignedInteger('creator_split_basis_points');
            $table->unsignedInteger('app_split_basis_points');
            $table->unsignedBigInteger('diamond_ngn_rate');
            $table->unsignedBigInteger('diamond_usd_rate');
            $table->unsignedBigInteger('transfer_fee_ngn_kobo');
            $table->unsignedBigInteger('transfer_fee_ngn_min_kobo');
            $table->unsignedBigInteger('transfer_fee_ngn_max_kobo');
            $table->unsignedBigInteger('transfer_fee_usd_cents');
            $table->unsignedBigInteger('min_cashout_diamonds');
            $table->boolean('active')->default(true)->index();
            $table->timestampTz('effective_at')->index();
            $table->text('reason');
            $table->timestampsTz();
            $table->unique(['code', 'effective_at'], 'coin_economy_regime_effective');
        });

        Schema::table('coin_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_ngn_kobo')->nullable()->after('unit');
            $table->unsignedInteger('price_usd_cents')->nullable()->after('price_ngn_kobo');
        });

        Schema::table('gifts', function (Blueprint $table): void {
            $table->foreignUlid('coin_ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignUlid('economy_regime_id')->nullable()->constrained('coin_economy_regimes')->restrictOnDelete();
        });

        Schema::table('iap_receipts', function (Blueprint $table): void {
            $table->foreignUlid('economy_regime_id')->nullable()->constrained('coin_economy_regimes')->restrictOnDelete();
        });

        Schema::create('diamond_cashouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('creator_profile_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payout_method_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ledger_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('economy_regime_id')->constrained('coin_economy_regimes')->restrictOnDelete();
            $table->unsignedBigInteger('diamonds');
            $table->string('currency', 3);
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('transfer_fee_minor');
            $table->bigInteger('net_minor');
            $table->unsignedInteger('store_fee_basis_points');
            $table->unsignedInteger('creator_split_basis_points');
            $table->unsignedBigInteger('diamond_rate');
            $table->string('state')->default('queued')->index();
            $table->string('idempotency_key')->unique();
            $table->char('request_hash', 64);
            $table->string('provider_reference')->nullable()->unique();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });

        CoinEconomy::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('diamond_cashouts');
        Schema::table('iap_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('economy_regime_id');
        });
        Schema::table('gifts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('economy_regime_id');
            $table->dropConstrainedForeignId('coin_ledger_transaction_id');
        });
        Schema::table('coin_products', function (Blueprint $table): void {
            $table->dropColumn(['price_ngn_kobo', 'price_usd_cents']);
        });
        Schema::dropIfExists('coin_economy_regimes');
    }
};
