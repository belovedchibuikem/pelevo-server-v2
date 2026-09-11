<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HomeMerchandisingTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_editor_can_publish_and_order_home_modules_with_audit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Home Editor', 'email' => 'home-editor@example.test', 'password' => 'Admin-password-9!', 'status' => 'active']);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'catalog_editor')->value('id')]);
        $module = DB::table('home_modules')->where('key', 'trending')->first();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->putJson('/api/admin/v1/catalog/home-modules/'.$module->id, [
                'title' => 'Trending across Pelevo',
                'subtitle' => 'Updated by the editorial team',
                'position' => 2,
                'active' => true,
                'starts_at' => null,
                'ends_at' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.module.title', 'Trending across Pelevo');

        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.home_module_saved', 'subject_id' => $module->id]);
    }
}
