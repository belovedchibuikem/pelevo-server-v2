<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\ConfigurationVersion;
use App\Models\GiftType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        ConfigurationVersion::firstOrCreate(['version' => 1], [
            'payload' => [
                'tabs' => ['home', 'search', 'library', 'earn', 'reels'],
                'flags' => ['earn_enabled' => true, 'gifts_enabled' => true, 'reels_upload' => true, 'ai_hub' => false, 'premium' => true, 'live' => true, 'referrals' => true, 'share_cards' => true],
                'money' => ['earn_min_withdraw_coins' => 1250, 'earn_coin_usd' => '0.004', 'display_ngn_per_coin' => '4.00', 'earn_lock_hours' => 72, 'reels_min_followers' => 100, 'reels_min_views' => 1000, 'reels_min_payout_ngn_minor' => 500000, 'gift_catalog_version' => 1],
                'limits' => ['comment_chars' => 400, 'comment_edit_minutes' => 15, 'reel_max_seconds' => 60, 'reel_qualified_view_ms' => 3000, 'reel_view_window_minutes' => 60, 'reel_qualified_views_per_window' => 1],
            ],
            'reason' => 'Initial authoritative product configuration',
            'effective_at' => now(),
        ]);

        foreach ([['applause', 'Applause', 100], ['love', 'Love', 500], ['star', 'Star', 1000], ['champion', 'Champion', 5000]] as [$slug,$name,$coins]) {
            GiftType::firstOrCreate(['slug' => $slug], ['name' => $name, 'coins' => $coins, 'version' => 1]);
        }

        foreach (['apple', 'google'] as $store) {
            foreach ([500, 1200, 2500, 6500, 15000] as $coins) {
                DB::table('coin_products')->insertOrIgnore(['id' => (string) Str::ulid(), 'store' => $store, 'product_id' => 'pelevo_coins_'.$coins, 'coins' => $coins, 'unit' => 'PCN', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $homeModules = [
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
        foreach ($homeModules as $position => [$key, $title, $subtitle, $kind, $source]) {
            DB::table('home_modules')->insertOrIgnore(['id' => (string) Str::ulid(), 'key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'kind' => $kind, 'source' => $source, 'position' => $position, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (['superadmin', 'support', 'moderator', 'finance', 'catalog_editor', 'analyst'] as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['users.view', 'users.suspend', 'catalog.write', 'claims.decide', 'moderation.act', 'finance.view', 'finance.adjust', 'payouts.approve', 'settings.write', 'broadcast.send', 'audit.view', 'ai.manage', 'operations.manage'] as $permission) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }
        $matrix = ['support' => ['users.view'], 'moderator' => ['moderation.act'], 'catalog_editor' => ['catalog.write', 'claims.decide'], 'finance' => ['finance.view', 'finance.adjust', 'payouts.approve'], 'analyst' => [], 'superadmin' => ['users.view', 'users.suspend', 'catalog.write', 'claims.decide', 'moderation.act', 'finance.view', 'finance.adjust', 'payouts.approve', 'settings.write', 'broadcast.send', 'audit.view', 'ai.manage', 'operations.manage']];
        foreach ($matrix as $role => $permissions) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            foreach ($permissions as $permission) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id')]);
            }
        }

        if (! app()->environment('testing')) {
            $this->seedLocalOperator();
        }
    }

    private function seedLocalOperator(): void
    {
        $email = strtolower((string) env('ADMIN_EMAIL', 'admin@pelevo.test'));
        $password = (string) env('ADMIN_PASSWORD', 'Admin-password-9!');
        $admin = Admin::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Pelevo Operator', 'password' => $password, 'status' => 'active'],
        );
        $roleId = DB::table('roles')->where('name', 'superadmin')->value('id');
        if ($roleId) {
            DB::table('admin_role')->insertOrIgnore([
                'admin_id' => $admin->id,
                'role_id' => $roleId,
            ]);
        }
    }
}
