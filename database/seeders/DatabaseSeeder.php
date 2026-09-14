<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\ConfigurationVersion;
use App\Models\GiftType;
use App\Support\AdminAccess;
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
            ['moods', 'What are you in the mood for?', 'Browse topics that match this moment', 'moods', 'moods'],
            ['pick_for_today', 'Your Pick for Today', 'One episode selected from Pelevo recommendations', 'hero_episode', 'made_for_you'],
            ['continue_listening', 'Continue Listening', 'Pick up where you left off', 'episode_progress', 'playback_progress'],
            ['made_for_you', 'Made For You', 'Personalized episode picks', 'episode_list', 'recommendations'],
            ['quick_listen', 'Quick Listen', 'Short episodes that fit your time', 'quick_listen', 'duration'],
            ['because_you_listened', 'Because You Listened', 'More from topics you already enjoy', 'because', 'listening_affinity'],
            ['trending', 'Trending on Pelevo', 'What listeners are playing now', 'ranked_shows', 'engagement'],
            ['new_from_following', 'New From Shows You Follow', 'Fresh episodes from your subscriptions', 'episode_list', 'following'],
            ['african_voices', 'African Voices', 'Podcasts from across the continent', 'shows', 'country'],
            ['try_something_new', 'Try Something New', 'Topics outside your listening history', 'try_new', 'unexplored_categories'],
            ['explore_by_topic', 'Explore by Topic', 'Browse the Pelevo catalog', 'topics', 'categories'],
            ['trending_shorts', 'Trending Shorts', 'Shorts gaining momentum', 'shorts', 'reels_trending'],
            ['shorts_for_you', 'Shorts You Might Like', 'Personalized short picks', 'shorts', 'reels_for_you'],
            ['browse_categories', 'Browse Categories', 'Jump into a format', 'browse', 'browse'],
        ];
        foreach ($homeModules as $position => [$key, $title, $subtitle, $kind, $source]) {
            DB::table('home_modules')->insertOrIgnore(['id' => (string) Str::ulid(), 'key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'kind' => $kind, 'source' => $source, 'position' => $position, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        AdminAccess::ensureRbac();

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
        AdminAccess::attachRole($admin->id);
    }
}
