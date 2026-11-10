<?php

use App\Services\Finance\CoinEconomy;
use App\Services\Finance\TokenValuation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $economy = app(CoinEconomy::class);
        $now = now();
        foreach (DB::table('coin_economy_regimes')->orderBy('id')->get() as $regime) {
            $min = $economy->minimumDiamonds((int) $regime->diamond_usd_rate, TokenValuation::GIFT_MIN_USD_CENTS);
            DB::table('coin_economy_regimes')->where('id', $regime->id)->update([
                'min_cashout_diamonds' => $min,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $economy = app(CoinEconomy::class);
        $now = now();
        foreach (DB::table('coin_economy_regimes')->orderBy('id')->get() as $regime) {
            $min = $economy->minimumDiamonds((int) $regime->diamond_usd_rate, 1_500);
            DB::table('coin_economy_regimes')->where('id', $regime->id)->update([
                'min_cashout_diamonds' => $min,
                'updated_at' => $now,
            ]);
        }
    }
};
