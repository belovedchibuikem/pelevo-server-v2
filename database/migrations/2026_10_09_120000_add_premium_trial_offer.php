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
        Schema::table('premium_plans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('trial_months')->default(0);
        });

        Schema::create('premium_offer_states', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('offer_slug');
            $table->unsignedInteger('session_count')->default(0);
            $table->string('last_session_id', 80)->nullable();
            $table->timestampTz('last_shown_at')->nullable();
            $table->timestampsTz();
            $table->primary(['user_id', 'offer_slug']);
        });

        if (! DB::table('premium_plans')->where('slug', 'plus-3-month-trial')->exists()) {
            DB::table('premium_plans')->insert([
                'id' => (string) Str::ulid(),
                'slug' => 'plus-3-month-trial',
                'name' => '3 Months Free',
                'price_minor' => 1600000,
                'currency' => 'NGN',
                'interval' => 'year',
                'trial_months' => 3,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_offer_states');
        DB::table('premium_plans')->where('slug', 'plus-3-month-trial')->delete();
        Schema::table('premium_plans', function (Blueprint $table): void {
            $table->dropColumn('trial_months');
        });
    }
};
