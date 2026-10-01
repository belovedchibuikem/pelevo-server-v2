<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reel_views', function (Blueprint $table): void {
            $table->uuid('feed_session_id')->nullable()->after('session_id');
            $table->unsignedInteger('server_watched_ms')->default(0)->after('watched_ms');
            $table->unsignedInteger('last_sequence')->default(0)->after('server_watched_ms');
            $table->timestampTz('last_heartbeat_at')->nullable()->after('last_sequence');
            $table->timestampTz('completed_at')->nullable()->after('last_heartbeat_at');
            $table->boolean('is_valid_view')->default(false)->after('qualified_at');
            $table->timestampTz('counted_at')->nullable()->after('is_valid_view');
            $table->string('fraud_state', 32)->default('clear')->after('counted_at');
            $table->index(['user_id', 'reel_id', 'counted_at'], 'reel_view_rolling_dedup_index');
        });

        Schema::create('reel_view_heartbeats', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reel_view_id')->constrained('reel_views')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reel_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_id');
            $table->unsignedInteger('sequence');
            $table->timestampTz('received_at');
            $table->unsignedInteger('playback_position_ms');
            $table->unsignedInteger('server_counted_ms')->default(0);
            $table->boolean('completed')->default(false);
            $table->boolean('app_foreground')->default(true);
            $table->boolean('audible')->default(true);
            $table->string('integrity_result', 32)->default('unverified');
            $table->char('ip_hash', 64)->nullable();
            $table->char('device_hash', 64)->nullable();
            $table->timestampTz('created_at');
            $table->unique(['reel_view_id', 'sequence'], 'reel_heartbeat_sequence_unique');
            $table->index(['user_id', 'received_at'], 'reel_heartbeat_user_time_index');
        });

        Schema::create('admob_reconciliation_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('admob_account_id');
            $table->date('statement_month');
            $table->string('timezone', 64)->default('America/Los_Angeles');
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('finalized_usd_micros');
            $table->unsignedBigInteger('estimated_usd_micros')->nullable();
            $table->bigInteger('adjustment_usd_micros')->default(0);
            $table->char('source_checksum', 64);
            $table->string('source_reference')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignUlid('imported_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['admob_account_id', 'statement_month'], 'admob_statement_unique');
        });

        Schema::create('ad_impressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('feed_session_id');
            $table->char('installation_hash', 64)->nullable();
            $table->string('platform', 16);
            $table->string('ad_unit_key', 64);
            $table->timestampTz('triggered_at')->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('watched_at')->nullable();
            $table->timestampTz('bounced_at')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('threshold');
            $table->unsignedTinyInteger('eligible_view_count');
            $table->unsignedBigInteger('payout_pool_usd_micros')->default(200);
            $table->unsignedSmallInteger('reserve_bps')->default(500);
            $table->bigInteger('estimated_value_micros')->nullable();
            $table->string('estimated_currency', 3)->nullable();
            $table->string('estimated_precision', 32)->nullable();
            $table->uuid('client_event_id')->nullable()->unique();
            $table->string('fraud_state', 32)->default('clear')->index();
            $table->foreignUlid('reconciliation_batch_id')->nullable()->constrained('admob_reconciliation_batches')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['user_id', 'feed_session_id', 'status'], 'ad_impression_session_index');
        });

        Schema::create('ad_impression_attributions', function (Blueprint $table): void {
            $table->foreignUuid('ad_impression_id')->constrained('ad_impressions')->cascadeOnDelete();
            $table->foreignUlid('reel_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('creator_profile_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('reel_view_id')->constrained('reel_views')->restrictOnDelete();
            $table->timestampTz('counted_at');
            $table->timestampsTz();
            $table->primary(['ad_impression_id', 'reel_view_id'], 'ad_impression_attribution_primary');
            $table->unique('reel_view_id', 'ad_attribution_reel_view_unique');
            $table->index(['creator_profile_id', 'counted_at'], 'ad_attribution_creator_time_index');
        });

        Schema::create('creator_ad_revenue_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUuid('ad_impression_id')->constrained('ad_impressions')->restrictOnDelete();
            $table->foreignUlid('creator_profile_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedBigInteger('gross_usd_micros');
            $table->unsignedBigInteger('confirmed_usd_micros')->default(0);
            $table->unsignedBigInteger('reserve_usd_micros')->default(0);
            $table->unsignedBigInteger('clawback_usd_micros')->default(0);
            $table->string('available_currency', 3)->nullable();
            $table->unsignedBigInteger('available_amount_minor')->default(0);
            $table->foreignUlid('pending_ledger_transaction_id')->constrained('ledger_transactions', indexName: 'ad_allocation_pending_tx_fk')->restrictOnDelete();
            $table->foreignUlid('release_ledger_transaction_id')->nullable()->constrained('ledger_transactions', indexName: 'ad_allocation_release_tx_fk')->restrictOnDelete();
            $table->foreignUlid('reversal_ledger_transaction_id')->nullable()->constrained('ledger_transactions', indexName: 'ad_allocation_reversal_tx_fk')->restrictOnDelete();
            $table->foreignUlid('reconciliation_batch_id')->nullable()->constrained('admob_reconciliation_batches')->restrictOnDelete();
            $table->foreignUlid('currency_conversion_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('review_reason_code', 64)->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('held_until')->index();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['ad_impression_id', 'creator_profile_id'], 'ad_allocation_impression_creator_unique');
        });

        Schema::create('currency_conversions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_profile_id')->constrained()->restrictOnDelete();
            $table->string('source_currency', 3);
            $table->unsignedBigInteger('source_amount_minor');
            $table->string('destination_currency', 3);
            $table->unsignedBigInteger('destination_amount_minor');
            $table->decimal('gross_rate', 24, 10);
            $table->decimal('net_rate', 24, 10);
            $table->unsignedSmallInteger('spread_bps')->default(0);
            $table->string('rate_source');
            $table->string('provider_reference')->nullable();
            $table->timestampTz('quoted_at');
            $table->timestampTz('quote_expires_at');
            $table->string('reason', 32);
            $table->string('idempotency_key')->unique();
            $table->foreignUlid('source_ledger_transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignUlid('destination_ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::table('creator_ad_revenue_allocations', function (Blueprint $table): void {
            $table->foreign('currency_conversion_id', 'ad_allocation_conversion_fk')
                ->references('id')
                ->on('currency_conversions')
                ->restrictOnDelete();
        });

        Schema::table('creator_payout_settings', function (Blueprint $table): void {
            $table->string('ad_payout_currency', 3)->nullable()->after('currency');
            $table->timestampTz('ad_payout_currency_effective_at')->nullable()->after('ad_payout_currency');
        });
        Schema::table('fx_rate_versions', function (Blueprint $table): void {
            $table->string('approval_state', 16)->default('approved')->index();
            $table->foreignUlid('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
        });

        DB::table('creator_payout_settings')
            ->whereIn('currency', ['USD', 'NGN'])
            ->update([
                'ad_payout_currency' => DB::raw('currency'),
                'ad_payout_currency_effective_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('fx_rate_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['approval_state', 'approved_at']);
        });
        Schema::table('creator_payout_settings', function (Blueprint $table): void {
            $table->dropColumn(['ad_payout_currency', 'ad_payout_currency_effective_at']);
        });
        Schema::table('creator_ad_revenue_allocations', function (Blueprint $table): void {
            $table->dropForeign('ad_allocation_conversion_fk');
        });
        Schema::dropIfExists('currency_conversions');
        Schema::dropIfExists('creator_ad_revenue_allocations');
        Schema::dropIfExists('ad_impression_attributions');
        Schema::dropIfExists('ad_impressions');
        Schema::dropIfExists('admob_reconciliation_batches');
        Schema::dropIfExists('reel_view_heartbeats');
        Schema::table('reel_views', function (Blueprint $table): void {
            $table->index('user_id', 'reel_views_user_id_foreign');
        });
        Schema::table('reel_views', function (Blueprint $table): void {
            $table->dropIndex('reel_view_rolling_dedup_index');
            $table->dropColumn([
                'feed_session_id',
                'server_watched_ms',
                'last_sequence',
                'last_heartbeat_at',
                'completed_at',
                'is_valid_view',
                'counted_at',
                'fraud_state',
            ]);
        });
    }
};
