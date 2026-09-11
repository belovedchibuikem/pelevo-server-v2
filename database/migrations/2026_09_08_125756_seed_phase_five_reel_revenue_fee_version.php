<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('fee_versions')->insertOrIgnore(['id' => (string) Str::ulid(), 'type' => 'reel_platform', 'basis_points' => 2000, 'effective_at' => now(), 'active' => true, 'reason' => 'Phase 5 initial reel revenue fee.', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Effective financial policy history is intentionally retained.
    }
};
