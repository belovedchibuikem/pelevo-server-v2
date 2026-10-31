<?php

use App\Models\GiftType;
use App\Services\Finance\CoinEconomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        GiftType::seed();
        CoinEconomy::seed();

        // Retire SKUs the mobile app no longer queries.
        DB::table('coin_products')
            ->where(function ($query): void {
                $query
                    ->where('product_id', 'like', 'pelevo_coins_%')
                    ->orWhere('product_id', 'like', 'coin_pack_%');
            })
            ->update(['active' => false, 'updated_at' => now()]);

        // Activate the Play Console IDs already created for Pelevo.
        DB::table('coin_products')
            ->whereIn('product_id', [
                'coins_300',
                'coins_500',
                'coins_750',
                'coins_1000',
                'coins_1500',
                'coins_2000',
            ])
            ->update(['active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('coin_products')
            ->where('product_id', 'like', 'pelevo_coins_%')
            ->orWhere('product_id', 'like', 'coin_pack_%')
            ->update(['active' => true, 'updated_at' => now()]);
    }
};
