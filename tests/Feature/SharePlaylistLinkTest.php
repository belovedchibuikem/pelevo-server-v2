<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SharePlaylistLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_playlist_and_collection_links_open_in_the_app(): void
    {
        $user = User::factory()->create();
        $playlistId = (string) Str::ulid();
        $collectionId = (string) Str::ulid();
        $privateId = (string) Str::ulid();
        $now = now();

        DB::table('playlists')->insert([
            [
                'id' => $playlistId,
                'user_id' => $user->id,
                'name' => 'Night Drive',
                'is_public' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $privateId,
                'user_id' => $user->id,
                'name' => 'Private Mix',
                'is_public' => false,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('collections')->insert([
            'id' => $collectionId,
            'user_id' => $user->id,
            'name' => 'Weekend Shows',
            'description' => null,
            'is_public' => true,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->get('/playlists/'.$playlistId)->assertOk()->assertSee('Night Drive');
        $this->getJson('/playlists/'.$playlistId)
            ->assertOk()
            ->assertJsonPath('data.subject_type', 'playlist')
            ->assertJsonPath('data.app_scheme_url', 'pelevo://playlist/'.$playlistId)
            ->assertJsonPath('data.web_path', '/playlists/'.$playlistId);
        $this->get('/playlists/'.$privateId)->assertNotFound();

        $this->get('/collections/'.$collectionId)->assertOk()->assertSee('Weekend Shows');
        $this->getJson('/collections/'.$collectionId)
            ->assertOk()
            ->assertJsonPath('data.app_scheme_url', 'pelevo://collection/'.$collectionId);
    }
}
