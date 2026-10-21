<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('premium_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('premium_subscriptions', function (Blueprint $table): void {
            $table->ulid('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->char('billing_country_code', 2)->nullable();
            $table->char('billing_currency', 3)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->timestampTz('grace_period_ends_at')->nullable();
            $table->boolean('is_in_intro_offer')->default(false);
            $table->string('current_product_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('premium_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'billing_country_code',
                'billing_currency',
                'amount_minor',
                'grace_period_ends_at',
                'is_in_intro_offer',
                'current_product_id',
            ]);
        });

        Schema::table('premium_subscriptions', function (Blueprint $table): void {
            $table->ulid('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
