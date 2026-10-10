<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->publish('plus-monthly', 'Monthly', 160000, 'month');
        $this->publish('plus-yearly', 'Annual', 1760000, 'year');
        DB::table('premium_plans')
            ->where('slug', 'plus-3-month-trial')
            ->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('premium_plans')->whereIn('slug', ['plus-monthly', 'plus-yearly'])->update([
            'trial_months' => 0,
            'active' => false,
            'updated_at' => now(),
        ]);
        DB::table('premium_plans')->where('slug', 'plus-3-month-trial')->update([
            'active' => true,
            'updated_at' => now(),
        ]);
    }

    private function publish(string $slug, string $name, int $priceMinor, string $interval): void
    {
        $values = [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'NGN',
            'interval' => $interval,
            'trial_months' => 3,
            'active' => true,
            'updated_at' => now(),
        ];
        $existing = DB::table('premium_plans')->where('slug', $slug)->first();
        if ($existing) {
            DB::table('premium_plans')->where('id', $existing->id)->update($values);

            return;
        }
        DB::table('premium_plans')->insert([
            'id' => (string) Str::ulid(),
            'slug' => $slug,
            'created_at' => now(),
            ...$values,
        ]);
    }
};
