<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_feed_states', function (Blueprint $table): void {
            $table->timestampTz('channel_synced_at')->nullable()->after('last_success_at');
        });
    }

    public function down(): void
    {
        Schema::table('show_feed_states', function (Blueprint $table): void {
            $table->dropColumn('channel_synced_at');
        });
    }
};
