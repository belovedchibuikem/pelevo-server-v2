<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('data_saver')->default(false);
            $table->string('audio_quality', 20)->default('high');
            $table->boolean('email_product')->default(false);
            $table->boolean('email_episodes')->default(false);
            $table->boolean('email_marketing')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            // Rollback discards these saved settings; prefer a forward fix.
            $table->dropColumn(['data_saver', 'audio_quality', 'email_product', 'email_episodes', 'email_marketing']);
        });
    }
};
