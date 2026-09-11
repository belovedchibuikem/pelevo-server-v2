<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE episodes MODIFY audio_url TEXT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE episodes ALTER COLUMN audio_url DROP NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("UPDATE episodes SET audio_url = '' WHERE audio_url IS NULL");
            DB::statement('ALTER TABLE episodes MODIFY audio_url TEXT NOT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("UPDATE episodes SET audio_url = '' WHERE audio_url IS NULL");
            DB::statement('ALTER TABLE episodes ALTER COLUMN audio_url SET NOT NULL');
        }
    }
};
