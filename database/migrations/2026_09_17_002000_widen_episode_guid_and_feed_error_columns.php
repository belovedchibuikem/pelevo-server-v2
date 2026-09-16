<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE episodes ALTER COLUMN guid TYPE TEXT');
            DB::statement('ALTER TABLE episodes ALTER COLUMN title TYPE TEXT');
            DB::statement('ALTER TABLE episodes ALTER COLUMN external_id TYPE TEXT');
            DB::statement('ALTER TABLE feed_sync_runs ALTER COLUMN error TYPE TEXT');
            DB::statement('ALTER TABLE show_feed_states ALTER COLUMN last_error TYPE TEXT');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Schema::table('episodes', function ($table): void {
                $table->dropUnique(['show_id', 'guid']);
            });
            DB::statement('ALTER TABLE episodes MODIFY guid VARCHAR(512) NOT NULL');
            DB::statement('ALTER TABLE episodes MODIFY title TEXT NOT NULL');
            DB::statement('ALTER TABLE episodes MODIFY external_id VARCHAR(191) NULL');
            Schema::table('episodes', function ($table): void {
                $table->unique(['show_id', 'guid']);
            });
            DB::statement('ALTER TABLE feed_sync_runs MODIFY error TEXT NULL');
            DB::statement('ALTER TABLE show_feed_states MODIFY last_error TEXT NULL');
        }
    }

    public function down(): void
    {
        // Widening columns is safe to leave in place.
    }
};
