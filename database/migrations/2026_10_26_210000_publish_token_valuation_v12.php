<?php

use App\Services\Finance\CoinEconomy;
use App\Services\Finance\TokenValuation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diamond_cashouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('gross_usd_cents')->nullable()->after('diamonds');
            $table->foreignUlid('fx_rate_version_id')->nullable()->after('diamond_rate')->constrained('fx_rate_versions')->nullOnDelete();
            $table->decimal('fx_rate', 20, 8)->nullable()->after('fx_rate_version_id');
        });

        Schema::table('creator_ad_revenue_allocations', function (Blueprint $table): void {
            $table->index(['creator_profile_id', 'held_until', 'status'], 'ad_allocation_clearance_index');
        });

        $this->publishRegimes();
        $this->alignAnchorPacks();
    }

    public function down(): void
    {
        Schema::table('creator_ad_revenue_allocations', function (Blueprint $table): void {
            $table->dropIndex('ad_allocation_clearance_index');
        });
        Schema::table('diamond_cashouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fx_rate_version_id');
            $table->dropColumn(['gross_usd_cents', 'fx_rate']);
        });
    }

    private function publishRegimes(): void
    {
        $now = now();
        $economy = new CoinEconomy;
        $replacements = [
            'standard' => [TokenValuation::DIAMOND_USD_STANDARD, '0.00052', true],
            'small_business' => [TokenValuation::DIAMOND_USD_SMALL_BUSINESS, '0.00063', false],
        ];
        foreach ($replacements as $code => [$rate, $previous, $active]) {
            $scaled = CoinEconomy::scaleRate($rate);
            $previousScaled = CoinEconomy::scaleRate($previous);
            $rows = DB::table('coin_economy_regimes')->where('code', $code)->where('diamond_usd_rate', $previousScaled)->get();
            foreach ($rows as $row) {
                $used = DB::table('gifts')->where('economy_regime_id', $row->id)->exists()
                    || DB::table('diamond_cashouts')->where('economy_regime_id', $row->id)->exists()
                    || DB::table('iap_receipts')->where('economy_regime_id', $row->id)->exists();
                $values = [
                    'diamond_ngn_rate' => $scaled,
                    'diamond_usd_rate' => $scaled,
                    'min_cashout_diamonds' => $economy->minimumDiamonds($scaled, TokenValuation::GIFT_MIN_USD_CENTS),
                    'reason' => $row->reason.' Updated to the v1.2 USD diamond rate '.$rate.'.',
                    'updated_at' => $now,
                ];
                if (! $used) {
                    DB::table('coin_economy_regimes')->where('id', $row->id)->update($values);

                    continue;
                }
                DB::table('coin_economy_regimes')->insert([
                    'id' => (string) Str::ulid(),
                    'code' => $row->code,
                    'name' => $row->name,
                    'store_fee_basis_points' => $row->store_fee_basis_points,
                    'creator_split_basis_points' => $row->creator_split_basis_points,
                    'app_split_basis_points' => $row->app_split_basis_points,
                    'diamond_ngn_rate' => $scaled,
                    'diamond_usd_rate' => $scaled,
                    'transfer_fee_ngn_kobo' => $row->transfer_fee_ngn_kobo,
                    'transfer_fee_ngn_min_kobo' => $row->transfer_fee_ngn_min_kobo,
                    'transfer_fee_ngn_max_kobo' => $row->transfer_fee_ngn_max_kobo,
                    'transfer_fee_usd_cents' => $row->transfer_fee_usd_cents,
                    'min_cashout_diamonds' => $values['min_cashout_diamonds'],
                    'active' => $active && (bool) $row->active,
                    'effective_at' => $now,
                    'reason' => 'v1.2 USD diamond rate '.$rate.' replaces a regime that already has ledger history.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function alignAnchorPacks(): void
    {
        $previous = [
            'coin_pack_300' => 35,
            'coin_pack_500' => 49,
            'coin_pack_750' => 79,
            'coin_pack_1000' => 99,
            'coin_pack_1500' => 149,
            'coin_pack_2000' => 199,
        ];
        foreach ($previous as $productId => $cents) {
            $coins = (int) DB::table('coin_products')->where('product_id', $productId)->value('coins');
            if ($coins < 1) {
                continue;
            }
            DB::table('coin_products')
                ->where('product_id', $productId)
                ->where('price_usd_cents', $cents)
                ->update([
                    'price_usd_cents' => TokenValuation::anchorUsdCents($coins),
                    'updated_at' => now(),
                ]);
        }
    }
};
