<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_settings', function (Blueprint $table): void {
            $table->boolean('auto_download_new')->default(false)->after('wifi_only');
        });
    }

    public function down(): void
    {
        Schema::table('download_settings', function (Blueprint $table): void {
            $table->dropColumn('auto_download_new');
        });
    }
};
