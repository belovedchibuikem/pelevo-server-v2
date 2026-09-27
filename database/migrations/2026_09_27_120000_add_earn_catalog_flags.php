<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $table->boolean('earn_enabled')->default(false)->index()->after('status');
        });
        Schema::table('earn_awards', function (Blueprint $table): void {
            $table->timestampTz('unlocked_at')->nullable()->index()->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('earn_awards', function (Blueprint $table): void {
            $table->dropIndex(['unlocked_at']);
            $table->dropColumn('unlocked_at');
        });
        Schema::table('shows', function (Blueprint $table): void {
            $table->dropIndex(['earn_enabled']);
            $table->dropColumn('earn_enabled');
        });
    }
};
