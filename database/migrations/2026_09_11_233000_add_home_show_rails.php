<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('home_modules')->where('key', 'african_voices')->update([
            'title' => 'African voices',
            'subtitle' => 'Podcasts from across the continent',
            'kind' => 'shows',
            'source' => 'country_shows',
            'updated_at' => now(),
        ]);

        $exists = DB::table('home_modules')->where('key', 'shows_you_follow')->exists();
        if (! $exists) {
            $position = (int) (DB::table('home_modules')->where('key', 'continue_listening')->value('position') ?? 2);
            DB::table('home_modules')->where('position', '>', $position)->increment('position');
            DB::table('home_modules')->insert([
                'id' => (string) Str::ulid(),
                'key' => 'shows_you_follow',
                'title' => 'Shows you follow',
                'subtitle' => 'Open a podcast to browse its episodes',
                'kind' => 'shows',
                'source' => 'following_shows',
                'position' => $position + 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('home_modules')->where('key', 'shows_you_follow')->delete();
        DB::table('home_modules')->where('key', 'african_voices')->update([
            'title' => 'African voices',
            'subtitle' => 'Shows published across the continent',
            'kind' => 'episodes',
            'source' => 'country',
            'updated_at' => now(),
        ]);
    }
};
