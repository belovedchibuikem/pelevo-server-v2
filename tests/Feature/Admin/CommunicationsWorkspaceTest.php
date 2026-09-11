<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CommunicationsWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_audience_preview_scheduling_cancellation_and_permission_boundaries(): void
    {
        Queue::fake();
        config(['features.notifications' => true]);
        $this->withoutVite();
        $this->operator();
        User::factory()->create(['status' => 'active', 'country_code' => 'NG']);
        User::factory()->create(['status' => 'active', 'country_code' => 'GB']);
        $template = (string) Str::ulid();
        DB::table('notification_templates')->insert(['id' => $template, 'key' => 'operations', 'version' => 1, 'title' => 'New episode', 'body' => 'Listen to the latest episode.', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/admin/communications/compose')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Communications')->has('templates', 1));
        $this->getJson('/api/admin/v1/communications/audience?country=NG')->assertOk()->assertJsonPath('data.recipients', 1);
        $id = $this->postJson('/api/admin/v1/advanced/broadcasts', ['template_id' => $template, 'audience' => ['country' => 'NG'], 'scheduled_at' => now()->addHour()->toIso8601String(), 'reason' => 'Announce new episode to listeners'])->assertCreated()->json('data.broadcast.id');
        $this->postJson('/api/admin/v1/communications/broadcasts/'.$id.'/cancel', ['reason' => 'The release date has changed'])->assertOk();
        $this->assertDatabaseHas('notification_broadcasts', ['id' => $id, 'state' => 'cancelled']);
        $this->postJson('/api/admin/v1/communications/broadcasts/'.$id.'/cancel', ['reason' => 'The release date has changed'])->assertConflict();
        $this->operator('support');
        $this->getJson('/api/admin/v1/communications/audience')->assertForbidden();
    }

    private function operator(string $role = 'superadmin'): Admin
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Operator', 'email' => Str::ulid().'@example.test', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);

        return $admin;
    }
}
