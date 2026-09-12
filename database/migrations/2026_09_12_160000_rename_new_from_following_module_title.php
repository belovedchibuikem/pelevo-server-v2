<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('home_modules')->where('key', 'new_from_following')->update([
            'title' => 'New From Shows You Follow',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('home_modules')->where('key', 'new_from_following')->update([
            'title' => 'New From People You Follow',
            'updated_at' => now(),
        ]);
    }
};
