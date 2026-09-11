<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const HOME_KEYS = [
        'moods',
        'pick_for_today',
        'continue_listening',
        'made_for_you',
        'quick_listen',
        'because_you_listened',
        'trending',
        'new_from_following',
        'african_voices',
        'try_something_new',
        'explore_by_topic',
    ];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => 'operations.manage', 'created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('roles')->where('name', 'superadmin')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'operations.manage')->value('id');
        if ($roleId && $permissionId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        $modules = [
            ['moods', 'What are you in the mood for?', 'Browse topics that match this moment', 'categories', 'moods'],
            ['pick_for_today', 'Your pick for today', 'One episode selected from Pelevo recommendations', 'hero_episode', 'made_for_you'],
            ['continue_listening', 'Continue listening', 'Pick up where you left off', 'episode_progress', 'playback_progress'],
            ['made_for_you', 'Made for you', 'Personalized episode picks', 'episodes', 'recommendations'],
            ['quick_listen', 'Quick listen', 'Short episodes that fit your time', 'episodes', 'duration'],
            ['because_you_listened', 'Because you listened', 'More from topics you already enjoy', 'episodes', 'listening_affinity'],
            ['trending', 'Trending on Pelevo', 'What listeners are playing now', 'ranked_episodes', 'engagement'],
            ['new_from_following', 'New from shows you follow', 'Fresh episodes from your subscriptions', 'episodes', 'following'],
            ['african_voices', 'African voices', 'Shows published across the continent', 'episodes', 'country'],
            ['try_something_new', 'Try something new', 'Topics outside your listening history', 'categories', 'unexplored_categories'],
            ['explore_by_topic', 'Explore by topic', 'Browse the Pelevo catalog', 'categories', 'categories'],
        ];
        foreach ($modules as $position => [$key, $title, $subtitle, $kind, $source]) {
            DB::table('home_modules')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'key' => $key,
                'title' => $title,
                'subtitle' => $subtitle,
                'kind' => $kind,
                'source' => $source,
                'position' => $position,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('home_modules')->whereIn('key', self::HOME_KEYS)->delete();
        $permissionId = DB::table('permissions')->where('name', 'operations.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
