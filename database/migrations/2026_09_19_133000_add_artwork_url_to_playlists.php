<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->string('artwork_url', 2048)->nullable()->after('description');
            $table->index(['is_public', 'updated_at', 'id'], 'playlists_public_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropIndex('playlists_public_updated_index');
            $table->dropColumn('artwork_url');
        });
    }
};
