<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShareLinkResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_share_token_resolves_to_episode_payload(): void
    {
        $user = User::factory()->create();
        $showId = (string) Str::ulid();
        $episodeId = (string) Str::ulid();
        $templateId = (string) Str::ulid();
        $token = Str::random(32);

        DB::table('shows')->insert([
            'id' => $showId,
            'rss_url' => 'https://example.test/feed.xml',
            'rss_url_hash' => hash('sha256', 'https://example.test/feed.xml'),
            'title' => 'Share Show',
            'author' => 'Host',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('episodes')->insert([
            'id' => $episodeId,
            'show_id' => $showId,
            'guid' => 'episode-guid-1',
            'title' => 'Share Episode',
            'audio_url' => 'https://example.test/audio.mp3',
            'availability' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('share_card_templates')->insert([
            'id' => $templateId,
            'name' => 'Default',
            'version' => 1,
            'schema' => json_encode(['fields' => []], JSON_THROW_ON_ERROR),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cardId = (string) Str::ulid();
        DB::table('share_cards')->insert([
            'id' => $cardId,
            'user_id' => $user->id,
            'share_card_template_id' => $templateId,
            'subject_type' => 'episode',
            'subject_id' => $episodeId,
            'state' => 'ready',
            'payload' => json_encode(['quote' => 'Listen'], JSON_THROW_ON_ERROR),
            'public_token' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $api = $this->getJson('/api/v1/s/'.$token)->assertOk();
        $api->assertJsonPath('data.subject_type', 'episode');
        $api->assertJsonPath('data.subject_id', $episodeId);
        $api->assertJsonPath('data.show_id', $showId);
        $api->assertJsonPath('data.web_path', '/episodes/'.$episodeId);
        $api->assertJsonPath('data.app_scheme_url', 'pelevo://episode/'.$episodeId);

        $this->getJson('/s/'.$token)
            ->assertOk()
            ->assertJsonPath('data.subject_id', $episodeId);

        $this->assertDatabaseHas('share_card_events', [
            'share_card_id' => $cardId,
            'event' => 'open',
        ]);
    }

    public function test_unknown_share_token_returns_not_found(): void
    {
        $this->getJson('/api/v1/s/'.Str::random(32))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
