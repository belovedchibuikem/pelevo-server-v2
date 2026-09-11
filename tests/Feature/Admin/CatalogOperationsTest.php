<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Episode;
use App\Models\Show;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalog_workspace_is_permission_protected_filterable_and_mutations_are_audited(): void
    {
        Bus::fake();
        $admin = Admin::create(['name' => 'Editor', 'email' => 'catalog@example.com', 'password' => 'password', 'status' => 'active']);
        $show = Show::create(['rss_url' => 'https://publisher.example/feed.xml', 'title' => 'Engineering Daily', 'author' => 'Pelevo']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'catalog-1', 'title' => 'Indexes', 'audio_url' => 'https://publisher.example/1.mp3']);
        DB::table('show_feed_states')->insert(['show_id' => $show->id, 'state' => 'failed', 'consecutive_failures' => 2, 'last_error' => 'HTTP 503', 'created_at' => now(), 'updated_at' => now()]);
        $session = ['admin_mfa_verified_at' => now()->timestamp];

        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/catalog')->assertForbidden();
        $role = DB::table('roles')->insertGetId(['name' => 'catalog-test', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->insertGetId(['name' => 'catalog.write', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);

        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/catalog?q=Engineering&feed_state=failed')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Catalog')->has('shows.data', 1));
        $this->actingAs($admin, 'admin')->withSession($session)->getJson('/api/admin/v1/catalog?feed_state=failed')->assertOk()->assertJsonPath('data.shows.total', 1);
        $this->actingAs($admin, 'admin')->withSession($session)->postJson("/api/admin/v1/catalog/shows/{$show->id}/refresh")->assertStatus(202)->assertJsonStructure(['data' => ['audit_reference']]);
        $category = $this->actingAs($admin, 'admin')->withSession($session)->putJson('/api/admin/v1/catalog/categories', ['name' => 'Technology', 'slug' => 'technology', 'position' => 1, 'active' => true])->assertOk();
        $category->assertJsonStructure(['data' => ['category', 'audit_reference']]);
        $this->actingAs($admin, 'admin')->withSession($session)->putJson('/api/admin/v1/catalog/playlists', ['title' => 'Editor Picks', 'published' => true, 'position' => 1, 'episode_ids' => [$episode->id]])->assertOk()->assertJsonStructure(['data' => ['audit_reference']]);
        $this->actingAs($admin, 'admin')->withSession($session)->putJson('/api/admin/v1/catalog/search-synonyms', ['term' => 'dev', 'synonym' => 'developer', 'active' => true])->assertOk();
        $this->assertDatabaseCount('audit_logs', 4);
    }
}
