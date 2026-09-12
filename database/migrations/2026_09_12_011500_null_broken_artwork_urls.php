<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shows')
            ->where('artwork_url', 'like', '%?.%')
            ->orWhere('artwork_url', 'like', '%?.jpg')
            ->orWhere('artwork_url', 'like', '%?.png')
            ->orWhere('artwork_url', 'like', '%?.webp')
            ->update(['artwork_url' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Irreversible cleanup of undecodable artwork URLs.
    }
};
