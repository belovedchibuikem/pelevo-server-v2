<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_ad_cashouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('creator_profile_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payout_method_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('fx_rate_version_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_usd_micros');
            $table->unsignedBigInteger('gross_usd_cents');
            $table->string('currency', 3);
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('net_minor');
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->string('state')->default('queued')->index();
            $table->string('idempotency_key')->unique();
            $table->char('request_hash', 64);
            $table->string('provider_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->index(['creator_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_ad_cashouts');
    }
};
