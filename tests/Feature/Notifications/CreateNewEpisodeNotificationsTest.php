<?php

namespace Tests\Feature\Notifications;

use App\Events\NewEpisodePublished;
use App\Listeners\CreateNewEpisodeNotifications;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CreateNewEpisodeNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_fans_out_once_and_respects_preferences(): void
    {
        $enabled = User::factory()->create();
        $disabled = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/feed.xml', 'title' => 'Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'one', 'title' => 'New episode', 'audio_url' => 'https://example.com/one.mp3']);
        $enabled->followedShows()->attach($show);
        $disabled->followedShows()->attach($show);
        DB::table('notification_preferences')->insert(['user_id' => $disabled->id, 'new_episodes' => false, 'push_enabled' => true, 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $listener = new CreateNewEpisodeNotifications;
        $listener->handle(new NewEpisodePublished($episode));
        $listener->handle(new NewEpisodePublished($episode));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $enabled->id, 'deduplication_key' => 'new-episode:'.$episode->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $disabled->id]);
    }
}
