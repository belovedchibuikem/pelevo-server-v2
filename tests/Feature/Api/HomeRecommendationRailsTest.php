<?php

namespace Tests\Feature\Api;

use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HomeRecommendationRailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_because_you_listened_uses_interest_signals_and_avoids_repeats(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $growth = DB::table('categories')->insertGetId(['name' => 'Personal Growth', 'slug' => 'personal-growth', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $comedy = DB::table('categories')->insertGetId(['name' => 'Comedy', 'slug' => 'comedy', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $seed = Show::create(['rss_url' => 'https://example.invalid/mindset.xml', 'title' => 'The Mindset Podcast', 'status' => 'active']);
        $relatedOne = Show::create(['rss_url' => 'https://example.invalid/growth.xml', 'title' => 'Growth Daily', 'status' => 'active']);
        $relatedTwo = Show::create(['rss_url' => 'https://example.invalid/success.xml', 'title' => 'Success Habits', 'status' => 'active']);
        $unrelated = Show::create(['rss_url' => 'https://example.invalid/jokes.xml', 'title' => 'Late Night Jokes', 'status' => 'active']);

        DB::table('category_show')->insert([
            ['category_id' => $growth, 'show_id' => $seed->id],
            ['category_id' => $growth, 'show_id' => $relatedOne->id],
            ['category_id' => $growth, 'show_id' => $relatedTwo->id],
            ['category_id' => $comedy, 'show_id' => $unrelated->id],
        ]);

        $finished = Episode::create(['show_id' => $seed->id, 'guid' => 'seed-1', 'title' => 'Mindset foundations', 'description' => 'Personal growth and success', 'audio_url' => 'https://example.invalid/a.mp3', 'duration_seconds' => 1200, 'published_at' => now()->subDay(), 'availability' => 'available']);
        $alsoFinished = Episode::create(['show_id' => $seed->id, 'guid' => 'seed-2', 'title' => 'Mindset repeats', 'audio_url' => 'https://example.invalid/b.mp3', 'duration_seconds' => 900, 'published_at' => now()->subHours(2), 'availability' => 'available']);
        $relatedA = Episode::create(['show_id' => $relatedOne->id, 'guid' => 'rel-a', 'title' => 'Growth habits', 'description' => 'Mindset for personal growth', 'audio_url' => 'https://example.invalid/c.mp3', 'duration_seconds' => 800, 'published_at' => now(), 'availability' => 'available']);
        $relatedB = Episode::create(['show_id' => $relatedOne->id, 'guid' => 'rel-b', 'title' => 'Another growth take', 'audio_url' => 'https://example.invalid/d.mp3', 'duration_seconds' => 700, 'published_at' => now()->subMinutes(10), 'availability' => 'available']);
        Episode::create(['show_id' => $relatedTwo->id, 'guid' => 'rel-c', 'title' => 'Success and mindset', 'audio_url' => 'https://example.invalid/e.mp3', 'duration_seconds' => 600, 'published_at' => now()->subMinutes(5), 'availability' => 'available']);
        Episode::create(['show_id' => $unrelated->id, 'guid' => 'joke-1', 'title' => 'Standup special', 'audio_url' => 'https://example.invalid/f.mp3', 'duration_seconds' => 500, 'published_at' => now(), 'availability' => 'available']);

        DB::table('playback_progress')->insert([
            ['user_id' => $user->id, 'episode_id' => $finished->id, 'position_seconds' => 1200, 'completed' => true, 'version' => 1, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            ['user_id' => $user->id, 'episode_id' => $alsoFinished->id, 'position_seconds' => 900, 'completed' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('follows')->insert(['user_id' => $user->id, 'show_id' => $seed->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('episode_saves')->insert(['user_id' => $user->id, 'episode_id' => $finished->id, 'created_at' => now(), 'updated_at' => now()]);

        $rail = $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/rails/because_you_listened')->assertOk()->json('data.rail');
        $titles = collect($rail['items'])->pluck('title')->all();
        $showIds = collect($rail['items'])->pluck('show_id')->all();

        $this->assertStringContainsString('The Mindset Podcast', (string) $rail['inspiration']['title']);
        $this->assertStringContainsString('Personal Growth', (string) $rail['inspiration']['subtitle']);
        $this->assertNotContains('Mindset foundations', $titles);
        $this->assertNotContains('Mindset repeats', $titles);
        $this->assertNotContains('Standup special', $titles);
        $this->assertNotContains($seed->id, $showIds);
        $this->assertCount(count(array_unique($showIds)), $showIds);
        $this->assertLessThanOrEqual(1, collect($showIds)->filter(fn (string $id): bool => $id === $relatedOne->id)->count());
        $this->assertTrue(in_array($relatedA->title, $titles, true) || in_array($relatedB->title, $titles, true));
    }

    public function test_african_voices_prefers_covers_and_rotates_per_listener(): void
    {
        Cache::flush();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $plain = Show::create(['rss_url' => 'https://example.invalid/plain.xml', 'title' => 'No Cover Voice', 'country_code' => 'NG', 'status' => 'active']);
        for ($index = 1; $index <= 8; $index++) {
            Show::create([
                'rss_url' => "https://example.invalid/ng-{$index}.xml",
                'title' => "Covered Voice {$index}",
                'artwork_url' => "https://covers.example.invalid/ng-{$index}.jpg",
                'country_code' => 'NG',
                'status' => 'active',
            ]);
        }

        $firstTitles = collect($this->actingAs($first, 'sanctum')->getJson('/api/v1/home/rails/african_voices?country=NG')->assertOk()->json('data.rail.items'))->pluck('title');
        $secondTitles = collect($this->actingAs($second, 'sanctum')->getJson('/api/v1/home/rails/african_voices?country=NG')->assertOk()->json('data.rail.items'))->pluck('title');

        $this->assertNotContains($plain->title, $firstTitles);
        $this->assertTrue($firstTitles->every(fn (string $title): bool => str_starts_with($title, 'Covered Voice')));
        $this->assertTrue(collect($this->actingAs($first, 'sanctum')->getJson('/api/v1/home/rails/african_voices?country=NG')->json('data.rail.items'))->every(fn (array $item): bool => is_string($item['artwork_url']) && $item['artwork_url'] !== ''));
        $this->assertNotSame($firstTitles->all(), $secondTitles->all());
    }

    public function test_quick_listen_rotates_matching_episodes_per_listener(): void
    {
        Cache::flush();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.invalid/quick.xml', 'title' => 'Quick Show', 'status' => 'active']);
        $titles = [];
        for ($index = 1; $index <= 10; $index++) {
            $episode = Episode::create([
                'show_id' => $show->id,
                'guid' => "quick-{$index}",
                'title' => "Quick Episode {$index}",
                'audio_url' => "https://example.invalid/q-{$index}.mp3",
                'duration_seconds' => 400 + $index,
                'published_at' => now()->subMinutes($index),
                'availability' => 'available',
            ]);
            $titles[] = $episode->title;
        }
        Episode::create([
            'show_id' => $show->id,
            'guid' => 'too-long',
            'title' => 'Long Episode',
            'audio_url' => 'https://example.invalid/long.mp3',
            'duration_seconds' => 2400,
            'published_at' => now(),
            'availability' => 'available',
        ]);

        $firstItems = collect($this->actingAs($first, 'sanctum')->getJson('/api/v1/home/rails/quick_listen?min_duration=300&max_duration=600')->assertOk()->json('data.rail.items'))->pluck('title');
        $secondItems = collect($this->actingAs($second, 'sanctum')->getJson('/api/v1/home/rails/quick_listen?min_duration=300&max_duration=600')->assertOk()->json('data.rail.items'))->pluck('title');

        $this->assertNotContains('Long Episode', $firstItems);
        $this->assertEqualsCanonicalizing($titles, $firstItems->all());
        $this->assertEqualsCanonicalizing($titles, $secondItems->all());
        $this->assertNotSame($firstItems->all(), $secondItems->all());
    }
}
