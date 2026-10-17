<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShareCreatorLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_creator_links_resolve_by_id_and_handle(): void
    {
        $user = User::factory()->create(['name' => 'Ada Creator', 'handle' => 'ada_creator']);
        $creator = CreatorProfile::create([
            'user_id' => $user->id,
            'display_name' => 'Stale Studio',
            'status' => 'active',
        ]);

        $this->get('/creators/'.$creator->id)->assertOk()->assertSee('Ada Creator');
        $this->get('/creator/ada_creator')->assertOk()->assertSee('Open in Pelevo');
        $this->getJson('/creators/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.subject_type', 'creator')
            ->assertJsonPath('data.subject_id', $creator->id)
            ->assertJsonPath('data.web_path', '/creators/'.$creator->id);
        $this->get('/creators/missing-creator')->assertNotFound();
    }
}
