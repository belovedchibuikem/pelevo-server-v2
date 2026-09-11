<?php

namespace Tests\Feature\Admin;

use App\Jobs\HydrateRssFeed;
use App\Models\Admin;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_view_scheduler_and_queue_diagnostics(): void
    {
        $this->withoutVite();
        [$admin, $session] = $this->operator();
        DB::table('scheduler_heartbeats')->insert(['id' => (string) Str::ulid(), 'host' => 'test-worker', 'ran_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get('/admin/operations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Operations')
                ->where('diagnostics.scheduler.healthy', true)
                ->has('manualTasks')
                ->has('setup'));
    }

    public function test_manual_task_requires_permission_fresh_mfa_reason_and_is_audited(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        [$admin, $session] = $this->operator();
        $payload = ['reason' => 'Operator requested catalog recovery', 'idempotency_key' => (string) Str::uuid()];

        $this->actingAs($admin, 'admin')->withSession($session)
            ->postJson('/api/admin/v1/operations/tasks/catalog:poll-feeds/run', $payload)
            ->assertAccepted();

        $this->assertDatabaseHas('admin_operation_requests', ['action' => 'schedule.run', 'target' => 'catalog:poll-feeds', 'state' => 'completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operations.schedule_run']);
    }

    public function test_readiness_is_token_protected(): void
    {
        config()->set('operations.readiness_token', 'test-readiness-token');

        $this->getJson('/operations/health')->assertUnauthorized();
        $this->withToken('test-readiness-token')->getJson('/operations/health')->assertServiceUnavailable()->assertJsonPath('data.scheduler.healthy', false);
    }

    private function operator(): array
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Operator', 'email' => Str::uuid().'@example.test', 'password' => 'Admin-password-9!', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'superadmin')->value('id')]);

        return [$admin, ['admin_mfa_verified_at' => now()->timestamp]];
    }
}
