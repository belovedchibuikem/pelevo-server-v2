<?php

namespace Tests\Feature\Api;

use App\Integrations\Rss\FeedUrlGuard;
use App\Models\Device;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EpisodeContentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_ordered_chapters_transcript_and_publisher_download_url(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'phone', 'name' => 'Phone']);
        $show = Show::create(['rss_url' => 'https://example.com/feed.xml', 'title' => 'Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'one', 'title' => 'One', 'audio_url' => 'https://publisher.example/one.mp3']);
        DB::table('episode_chapters')->insert([['id' => (string) Str::ulid(), 'episode_id' => $episode->id, 'starts_at_seconds' => 60, 'title' => 'Second', 'created_at' => now(), 'updated_at' => now()], ['id' => (string) Str::ulid(), 'episode_id' => $episode->id, 'starts_at_seconds' => 0, 'title' => 'First', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('episode_transcripts')->insert(['id' => (string) Str::ulid(), 'episode_id' => $episode->id, 'format' => 'text', 'content' => 'Transcript', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/episodes/{$episode->id}/chapters")->assertOk()->assertJsonPath('data.0.title', 'First');
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/episodes/{$episode->id}/transcript")->assertOk()->assertJsonPath('data.content', 'Transcript');
        $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', 'phone')->postJson("/api/v1/downloads/authorize/{$episode->id}")->assertOk()->assertJsonPath('data.url', 'https://publisher.example/one.mp3')->assertJsonStructure(['data' => ['authorization', 'expires_at']]);
        $this->assertDatabaseHas('downloads', ['user_id' => $user->id, 'episode_id' => $episode->id, 'device_id' => $device->id]);
    }

    public function test_episode_stream_forwards_valid_ranges_and_preserves_partial_response_headers(): void
    {
        Http::preventStrayRequests();
        Http::fake(['publisher.example/audio.mp3' => Http::response('56789', 206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 5-9/10', 'ETag' => 'audio-v1'])]);
        $this->app->instance(FeedUrlGuard::class, new class extends FeedUrlGuard
        {
            public function ensureSafe(string $url): void {}
        });
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/range.xml', 'title' => 'Range Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'range', 'title' => 'Range Episode', 'audio_url' => 'https://publisher.example/audio.mp3']);

        $response = $this->actingAs($user, 'sanctum')->withHeader('Range', 'bytes=5-9')->get("/api/v1/episodes/{$episode->id}/stream");

        $response->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Range', 'bytes 5-9/10')->assertHeader('Content-Length', '5')->assertStreamedContent('56789');
        Http::assertSent(fn ($request): bool => $request->hasHeader('Range', 'bytes=5-9'));
    }

    public function test_episode_stream_rejects_malformed_or_unsatisfiable_ranges(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/range-errors.xml', 'title' => 'Range Errors']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'range-errors', 'title' => 'Range Errors', 'audio_url' => 'https://publisher.example/audio.mp3']);
        $this->actingAs($user, 'sanctum')->withHeader('Range', 'items=1-2')->get("/api/v1/episodes/{$episode->id}/stream")->assertStatus(416)->assertHeader('Accept-Ranges', 'bytes');

        Http::preventStrayRequests();
        Http::fake(['publisher.example/audio.mp3' => Http::response('', 416, ['Content-Range' => 'bytes */10'])]);
        $this->app->instance(FeedUrlGuard::class, new class extends FeedUrlGuard
        {
            public function ensureSafe(string $url): void {}
        });
        $this->actingAs($user, 'sanctum')->withHeader('Range', 'bytes=99-100')->get("/api/v1/episodes/{$episode->id}/stream")->assertStatus(416)->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_episode_stream_revalidates_every_redirect_destination(): void
    {
        Http::preventStrayRequests();
        Http::fake(['publisher.example/start.mp3' => Http::response('', 302, ['Location' => 'https://cdn.example/final.mp3']), 'cdn.example/final.mp3' => Http::response('audio', 206, ['Content-Range' => 'bytes 0-4/5'])]);
        $guard = new class extends FeedUrlGuard
        {
            public array $checked = [];

            public function ensureSafe(string $url): void
            {
                $this->checked[] = $url;
            }
        };
        $this->app->instance(FeedUrlGuard::class, $guard);
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/redirect.xml', 'title' => 'Redirect Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'redirect', 'title' => 'Redirect', 'audio_url' => 'https://publisher.example/start.mp3']);

        $this->actingAs($user, 'sanctum')->withHeader('Range', 'bytes=0-4')->get("/api/v1/episodes/{$episode->id}/stream")->assertStatus(206)->assertStreamedContent('audio');
        $this->assertSame(['https://publisher.example/start.mp3', 'https://cdn.example/final.mp3'], $guard->checked);
        Http::assertSentCount(2);
    }

    public function test_episode_stream_supports_relative_redirects_and_publishers_ignoring_range(): void
    {
        Http::preventStrayRequests();
        Http::fake(['publisher.example/media/start' => Http::response('', 302, ['Location' => '../audio.aac']), 'publisher.example/audio.aac' => Http::response('complete-audio', 200, ['Content-Type' => 'audio/aac', 'ETag' => 'aac-v1'])]);
        $this->app->instance(FeedUrlGuard::class, new class extends FeedUrlGuard
        {
            public function ensureSafe(string $url): void {}
        });
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/compat.xml', 'title' => 'Compatibility']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'compat', 'title' => 'Compatibility', 'audio_url' => 'https://publisher.example/media/start']);

        $this->actingAs($user, 'sanctum')->withHeader('Range', 'bytes=0-4')->get("/api/v1/episodes/{$episode->id}/stream")->assertOk()->assertHeader('Content-Type', 'audio/aac')->assertHeader('ETag', 'aac-v1')->assertStreamedContent('complete-audio');
        Http::assertSentCount(2);
    }
}
