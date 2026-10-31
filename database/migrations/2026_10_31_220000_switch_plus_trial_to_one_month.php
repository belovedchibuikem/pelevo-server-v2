<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('premium_plans')
            ->whereIn('slug', ['plus-monthly', 'plus-yearly'])
            ->update([
                'trial_months' => 1,
                'updated_at' => now(),
            ]);

        DB::table('premium_plans')
            ->where('slug', 'plus-3-month-trial')
            ->update([
                'active' => false,
                'name' => '3 Months Free (legacy)',
                'updated_at' => now(),
            ]);

        $existing = DB::table('premium_plans')->where('slug', 'plus-1-month-trial')->first();
        $values = [
            'name' => '1 Month Free',
            'price_minor' => 1760000,
            'currency' => 'NGN',
            'interval' => 'year',
            'trial_months' => 1,
            'active' => false,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('premium_plans')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('premium_plans')->insert([
            'id' => (string) Str::ulid(),
            'slug' => 'plus-1-month-trial',
            'created_at' => now(),
            ...$values,
        ]);
    }

    public function down(): void
    {
        DB::table('premium_plans')
            ->whereIn('slug', ['plus-monthly', 'plus-yearly'])
            ->update([
                'trial_months' => 3,
                'updated_at' => now(),
            ]);
        DB::table('premium_plans')->where('slug', 'plus-1-month-trial')->delete();
    }
};
