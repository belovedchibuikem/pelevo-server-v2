<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creator African bank payouts need an approved USD→NGN FX row.
 * Ads quote freshness still uses DisplayFx::quote(); payouts use quoteForPayout().
 */
return new class extends Migration
{
    private const RATE = '1346.79000000';

    private const SOURCE = 'ops.seed.usd_ngn.2026_11_09';

    public function up(): void
    {
        $exists = DB::table('fx_rate_versions')
            ->where('base_unit', 'USD')
            ->where('quote_currency', 'NGN')
            ->where('approval_state', 'approved')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        DB::table('fx_rate_versions')->insert([
            'id' => (string) Str::ulid(),
            'base_unit' => 'USD',
            'quote_currency' => 'NGN',
            'rate' => self::RATE,
            'source' => self::SOURCE,
            'effective_at' => $now,
            'approval_state' => 'approved',
            'created_by' => null,
            'approved_by' => null,
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('fx_rate_versions')
            ->where('source', self::SOURCE)
            ->delete();
    }
};
