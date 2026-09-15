<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_center_exposes_operational_groups_incidents_and_audit(): void
    {
        $this->withoutVite();
        Cache::forget('admin:command-center:v1');
        User::factory()->count(2)->create();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Operator', 'email' => 'dashboard@example.com', 'password' => 'Admin-password-9!', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'superadmin')->value('id')]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->has('metricGroups.audience')->has('metricGroups.listening')->has('metricGroups.catalog')->has('metricGroups.creators')->has('metricGroups.moderation')->has('metricGroups.money')->has('metricGroups.operations')->has('incidents')->has('recentAudit')->where('metricGroups.audience.total_users', 2));
    }

    public function test_trends_compare_equal_periods_with_exact_links_and_permission_scoping(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        User::factory()->create(['created_at' => '2026-09-08 12:00:00']);
        User::factory()->count(2)->create(['created_at' => '2026-09-07 12:00:00']);
        $admin = Admin::create(['name' => 'Support', 'email' => 'chart-scope@example.test', 'password' => 'Admin-password-9!', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'support')->value('id')]);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $this->get('/admin?date_from=2026-09-08&date_to=2026-09-08')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('metricGroups.money')->missing('metricGroups.operations')
            ->where('charts.0.label', 'New accounts')
            ->where('charts.0.points.0.value', 1)
            ->where('charts.0.points.0.previous', 2)
            ->where('charts.0.points.0.previous_date', '2026-09-07')
            ->where('charts.0.href', '/admin/users?view=directory&date_from=2026-09-08&date_to=2026-09-08')
            ->where('charts', fn ($charts): bool => collect($charts)->every(fn ($chart): bool => in_array($chart['label'], ['New accounts', 'Support tickets', 'Website contact'], true))));
        $this->get('/admin?date_from=2026-01-01&date_to=2026-09-08')->assertUnprocessable();
    }
}
