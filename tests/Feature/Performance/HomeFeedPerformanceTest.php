<?php

namespace Tests\Feature\Performance;

use App\Actions\Catalog\BuildHomeFeed;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HomeFeedPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_materialized_warm_home_p95_is_below_300_milliseconds_and_read_paths_are_indexed(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 40) as $i) {
            $show = Show::create(['rss_url' => "https://publisher.example/{$i}.xml", 'title' => "Show {$i}"]);
            foreach (range(1, 5) as $j) {
                Episode::create(['show_id' => $show->id, 'guid' => "{$i}-{$j}", 'title' => "Episode {$i}-{$j}", 'audio_url' => "https://publisher.example/{$i}-{$j}.mp3", 'published_at' => now()->subMinutes($i * $j)]);
            }
            if ($i <= 10) {
                DB::table('follows')->insert(['user_id' => $user->id, 'show_id' => $show->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $rails = app(BuildHomeFeed::class)->handle($user->id);
        DB::table('home_feed_snapshots')->insert(['user_id' => $user->id, 'rails' => json_encode($rails), 'version' => 1, 'generated_at' => now(), 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
        $samples = [];
        foreach (range(1, 25) as $_) {
            $start = hrtime(true);
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/feed')->assertOk();
            $samples[] = (hrtime(true) - $start) / 1_000_000;
        }
        sort($samples);
        $p95 = $samples[(int) ceil(count($samples) * .95) - 1];
        $this->assertLessThanOrEqual(300.0, $p95, sprintf('Warm home p95 was %.2fms.', $p95));

        if (DB::getDriverName() === 'mysql') {
            $indexes = collect(DB::select('SHOW INDEX FROM episodes'))->pluck('Key_name');
            $this->assertContains('episodes_discovery_cursor_index', $indexes);
            $this->assertNotEmpty(DB::select("EXPLAIN SELECT id, show_id, published_at FROM episodes WHERE availability = 'available' ORDER BY published_at DESC, id DESC LIMIT 20"));
        }
    }
}
