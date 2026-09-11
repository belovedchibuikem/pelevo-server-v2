<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('home_modules')->where('key', 'shows_you_follow')->update([
            'active' => false,
            'updated_at' => now(),
        ]);

        $updates = [
            'moods' => ['title' => 'What are you in the mood for?', 'subtitle' => 'Browse topics that match this moment', 'kind' => 'moods'],
            'pick_for_today' => ['title' => 'Your Pick for Today', 'subtitle' => 'One episode selected from Pelevo recommendations', 'kind' => 'hero_episode'],
            'continue_listening' => ['title' => 'Continue Listening', 'subtitle' => 'Pick up where you left off', 'kind' => 'episode_progress'],
            'made_for_you' => ['title' => 'Made For You', 'subtitle' => 'Personalized episode picks', 'kind' => 'episode_list'],
            'quick_listen' => ['title' => 'Quick Listen', 'subtitle' => 'Short episodes that fit your time', 'kind' => 'quick_listen'],
            'because_you_listened' => ['title' => 'Because You Listened', 'subtitle' => 'More from topics you already enjoy', 'kind' => 'because'],
            'trending' => ['title' => 'Trending on Pelevo', 'subtitle' => 'What listeners are playing now', 'kind' => 'ranked_shows'],
            'new_from_following' => ['title' => 'New From People You Follow', 'subtitle' => 'Fresh episodes from your subscriptions', 'kind' => 'episode_list'],
            'african_voices' => ['title' => 'African Voices', 'subtitle' => 'Podcasts from across the continent', 'kind' => 'shows'],
            'try_something_new' => ['title' => 'Try Something New', 'subtitle' => 'Topics outside your listening history', 'kind' => 'try_new'],
            'explore_by_topic' => ['title' => 'Explore by Topic', 'subtitle' => 'Browse the Pelevo catalog', 'kind' => 'topics'],
        ];

        foreach ($updates as $key => $fields) {
            DB::table('home_modules')->where('key', $key)->update([...$fields, 'updated_at' => now()]);
        }

        $extras = [
            ['trending_shorts', 'Trending Shorts', 'Shorts gaining momentum', 'shorts', 'reels_trending'],
            ['shorts_for_you', 'Shorts You Might Like', 'Personalized short picks', 'shorts', 'reels_for_you'],
            ['browse_categories', 'Browse Categories', 'Jump into a format', 'browse', 'browse'],
        ];

        $position = (int) (DB::table('home_modules')->max('position') ?? 10);
        foreach ($extras as [$key, $title, $subtitle, $kind, $source]) {
            if (DB::table('home_modules')->where('key', $key)->exists()) {
                DB::table('home_modules')->where('key', $key)->update([
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'kind' => $kind,
                    'source' => $source,
                    'active' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }
            $position++;
            DB::table('home_modules')->insert([
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

        // Canonical scroll order matching the home mockups.
        $order = [
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
            'trending_shorts',
            'shorts_for_you',
            'browse_categories',
        ];
        foreach ($order as $index => $key) {
            DB::table('home_modules')->where('key', $key)->update([
                'position' => $index,
                'active' => true,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('home_modules')->whereIn('key', ['trending_shorts', 'shorts_for_you', 'browse_categories'])->delete();
        DB::table('home_modules')->where('key', 'shows_you_follow')->update(['active' => true, 'updated_at' => now()]);
    }
};
