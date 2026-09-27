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
        $permission = DB::table('permissions')->where('name', 'catalog.write')->value('id') ?: DB::table('permissions')->insertGetId(['name' => 'catalog.write', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);

        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/catalog?q=Engineering&feed_state=failed')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Catalog')->has('shows.data', 1));
        $this->actingAs($admin, 'admin')->withSession($session)->getJson('/api/admin/v1/catalog?feed_state=failed')->assertOk()->assertJsonPath('data.shows.total', 1);
        $this->actingAs($admin, 'admin')->withSession($session)->postJson("/api/admin/v1/catalog/shows/{$show->id}/refresh")->assertStatus(202)->assertJsonStructure(['data' => ['audit_reference']]);
        $category = $this->actingAs($admin, 'admin')->withSession($session)->putJson('/api/admin/v1/catalog/categories', ['name' => 'Technology', 'slug' => 'technology', 'position' => 1, 'active' => true])->assertOk();
        $category->assertJsonStructure(['data' => ['category', 'audit_reference']]);
        $this->actingAs($admin, 'admin')->withSession($session)->putJson('/api/admin/v1/catalog/playlists', ['title' => 'Editor Picks', 'published' => true, 'position' => 1, 'episode_ids' => [$episode->id]])->assertOk()->assertJsonStructure(['data' => ['audit_reference']]);
        $this->actingAs($admin, 'admin')->withSession($session)->putJson('/api/admin/v1/catalog/search-synonyms', ['term' => 'dev', 'synonym' => 'developer', 'active' => true])->assertOk();
        $this->actingAs($admin, 'admin')->withSession($session)->putJson("/api/admin/v1/catalog/shows/{$show->id}/earn", ['earn_enabled' => true])->assertOk()->assertJsonPath('data.earn_enabled', true);
        $this->assertTrue((bool) $show->fresh()->earn_enabled);
        $this->assertDatabaseCount('audit_logs', 5);
    }

    public function test_earn_podcasts_page_filters_ordinary_shows_and_saves_niche_order(): void
    {
        $admin = Admin::create(['name' => 'Editor', 'email' => 'earn-desk@example.com', 'password' => 'password', 'status' => 'active']);
        $session = ['admin_mfa_verified_at' => now()->timestamp];
        $role = DB::table('roles')->insertGetId(['name' => 'earn-desk', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'catalog.write')->value('id') ?: DB::table('permissions')->insertGetId(['name' => 'catalog.write', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Business', 'slug' => 'business', 'position' => 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $earning = Show::create(['rss_url' => 'https://publisher.example/earn.xml', 'title' => 'Money Talk', 'author' => 'Pelevo', 'earn_enabled' => true]);
        $ordinary = Show::create(['rss_url' => 'https://publisher.example/ordinary.xml', 'title' => 'Night Drive', 'author' => 'Pelevo']);

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get('/admin/earn-podcasts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/EarnCatalog')->where('filters.listing', 'earning')->has('shows.data', 1)->where('shows.data.0.title', 'Money Talk'));
        $this->actingAs($admin, 'admin')->withSession($session)
            ->get('/admin/earn-podcasts?listing=ordinary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.listing', 'ordinary')->has('shows.data', 1)->where('shows.data.0.title', 'Night Drive'));

        $this->actingAs($admin, 'admin')->withSession($session)
            ->putJson("/api/admin/v1/catalog/shows/{$ordinary->id}/earn", [
                'earn_enabled' => true,
                'earn_category_id' => $categoryId,
                'earn_position' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.earn_enabled', true);

        $ordinary->refresh();
        $this->assertTrue($ordinary->earn_enabled);
        $this->assertSame($categoryId, (int) $ordinary->earn_category_id);
        $this->assertSame(2, $ordinary->earn_position);

        $this->actingAs($admin, 'admin')->withSession($session)
            ->putJson("/api/admin/v1/catalog/shows/{$ordinary->id}/earn", ['earn_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.earn_enabled', false);
        $this->assertFalse((bool) $ordinary->fresh()->earn_enabled);
        $this->assertSame($categoryId, (int) $ordinary->fresh()->earn_category_id);
    }
}
