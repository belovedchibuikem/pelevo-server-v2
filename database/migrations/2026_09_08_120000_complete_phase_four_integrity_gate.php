<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type');
            $table->unsignedInteger('basis_points');
            $table->timestampTz('effective_at')->index();
            $table->boolean('active')->default(true);
            $table->text('reason');
            $table->timestampsTz();
            $table->unique(['type', 'effective_at']);
        });
        Schema::create('fx_rate_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('base_unit', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 20, 8);
            $table->string('source');
            $table->timestampTz('effective_at')->index();
            $table->timestampsTz();
            $table->unique(['base_unit', 'quote_currency', 'effective_at'], 'fx_version_unique');
        });
        Schema::table('gifts', function (Blueprint $table): void {
            $table->foreignUlid('fee_version_id')->nullable()->constrained('fee_versions')->restrictOnDelete();
        });
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->char('request_hash', 64)->nullable();
            $table->foreignUlid('fx_rate_version_id')->nullable()->constrained('fx_rate_versions')->restrictOnDelete();
        });
        Schema::create('earn_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('state')->default('draft')->index();
            $table->unsignedInteger('config_version')->default(1);
            $table->json('rules');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
        });
        Schema::table('earn_sessions', function (Blueprint $table): void {
            $table->foreignUlid('earn_campaign_id')->nullable()->constrained('earn_campaigns')->restrictOnDelete();
            $table->string('active_guard')->nullable();
            $table->char('nonce_hash', 64)->nullable();
            $table->timestampTz('nonce_expires_at')->nullable();
            $table->unique(['user_id', 'active_guard'], 'earn_one_active_session');
        });
        Schema::create('payout_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('withdrawal_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('state')->index();
            $table->string('provider_reference')->nullable()->index();
            $table->char('request_hash', 64);
            $table->json('response_payload')->nullable();
            $table->timestampsTz();
            $table->unique(['withdrawal_id', 'attempt']);
        });
        Schema::create('provider_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider');
            $table->string('provider_settlement_id');
            $table->date('business_date')->index();
            $table->string('currency', 3);
            $table->bigInteger('gross_minor');
            $table->bigInteger('fees_minor')->default(0);
            $table->bigInteger('net_minor');
            $table->string('state')->default('imported')->index();
            $table->char('payload_hash', 64);
            $table->timestampsTz();
            $table->unique(['provider', 'provider_settlement_id']);
        });
        Schema::create('finance_exceptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type')->index();
            $table->string('provider')->nullable();
            $table->string('reference')->nullable()->index();
            $table->string('state')->default('open')->index();
            $table->json('evidence');
            $table->text('resolution')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        DB::table('fee_versions')->insert([
            'id' => (string) Str::ulid(), 'type' => 'gift_platform', 'basis_points' => 1000,
            'effective_at' => now(), 'active' => true, 'reason' => 'Phase 4 initial platform gift fee.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER ledger_entries_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'");
            DB::unprepared("CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
        }
        Schema::dropIfExists('finance_exceptions');
        Schema::dropIfExists('provider_settlements');
        Schema::dropIfExists('payout_attempts');
        Schema::table('earn_sessions', function (Blueprint $table): void {
            $table->dropUnique('earn_one_active_session');
            $table->dropConstrainedForeignId('earn_campaign_id');
            $table->dropColumn(['active_guard', 'nonce_hash', 'nonce_expires_at']);
        });
        Schema::dropIfExists('earn_campaigns');
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fx_rate_version_id');
            $table->dropColumn('request_hash');
        });
        Schema::table('gifts', fn (Blueprint $table) => $table->dropConstrainedForeignId('fee_version_id'));
        Schema::dropIfExists('fx_rate_versions');
        Schema::dropIfExists('fee_versions');
    }
};
