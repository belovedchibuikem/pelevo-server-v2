<?php

namespace Tests\Feature\Admin;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Jobs\MergeDuplicateShow;
use App\Models\Admin;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogMergeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_blocks_identity_conflicts_and_governed_job_merges_without_losing_aggregates(): void
    {
        $admin = $this->admin();
        $client = $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp, 'admin_mfa_fresh_at' => now()->timestamp]);
        $survivor = Show::create(['rss_url' => 'https://example.com/s.xml', 'title' => 'Survivor']);
        $duplicate = Show::create(['rss_url' => 'https://example.com/d.xml', 'title' => 'Duplicate']);
        Episode::create(['show_id' => $survivor->id, 'guid' => 'collision', 'title' => 'One', 'audio_url' => 'https://example.com/1.mp3']);
        $conflict = Episode::create(['show_id' => $duplicate->id, 'guid' => 'collision', 'title' => 'Two', 'audio_url' => 'https://example.com/2.mp3']);
        $client->postJson('/api/admin/v1/catalog/merges/preview', ['survivor_show_id' => $survivor->id, 'duplicate_show_id' => $duplicate->id])->assertOk()->assertJsonPath('data.conflicts.0.type', 'episode_identity');
        $client->postJson('/api/admin/v1/catalog/merges', ['survivor_show_id' => $survivor->id, 'duplicate_show_id' => $duplicate->id, 'confirmation' => 'MERGE '.$duplicate->id, 'reason' => 'Confirmed duplicate podcast feed.', 'idempotency_key' => 'merge-conflict'])->assertConflict();
        $conflict->delete();
        $moved = Episode::create(['show_id' => $duplicate->id, 'guid' => 'unique', 'title' => 'Move', 'audio_url' => 'https://example.com/m.mp3']);
        $follower = User::factory()->create();
        DB::table('follows')->insert(['user_id' => $follower->id, 'show_id' => $duplicate->id, 'created_at' => now(), 'updated_at' => now()]);
        Queue::fake([MergeDuplicateShow::class]);
        $response = $client->postJson('/api/admin/v1/catalog/merges', ['survivor_show_id' => $survivor->id, 'duplicate_show_id' => $duplicate->id, 'confirmation' => 'MERGE '.$duplicate->id, 'reason' => 'Confirmed duplicate podcast feed.', 'idempotency_key' => 'merge-success'])->assertAccepted();
        $merge = $response->json('data.merge_id');
        Queue::assertPushed(MergeDuplicateShow::class, fn ($job) => $job->mergeId === $merge);
        (new MergeDuplicateShow($merge))->handle(app(InvalidateDiscoveryCache::class));
        $this->assertDatabaseHas('episodes', ['id' => $moved->id, 'show_id' => $survivor->id]);
        $this->assertDatabaseHas('follows', ['user_id' => $follower->id, 'show_id' => $survivor->id]);
        $this->assertDatabaseHas('shows', ['id' => $duplicate->id, 'status' => 'merged']);
        $this->assertDatabaseHas('show_redirects', ['source_show_id' => $duplicate->id, 'destination_show_id' => $survivor->id]);
        $this->actingAs($follower, 'sanctum')->getJson("/api/v1/shows/{$duplicate->id}")->assertStatus(308)->assertHeader('Location');
        $client->getJson("/api/admin/v1/catalog/merges/{$merge}")->assertOk()->assertJsonPath('data.state', 'completed');
    }

    private function admin(): Admin
    {
        $admin = Admin::create(['name' => 'Catalog', 'email' => Str::random(8).'@example.com', 'password' => 'password', 'status' => 'active']);
        $role = DB::table('roles')->insertGetId(['name' => 'merge-'.Str::random(5), 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'catalog.write')->value('id') ?: DB::table('permissions')->insertGetId(['name' => 'catalog.write', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);

        return $admin;
    }
}
