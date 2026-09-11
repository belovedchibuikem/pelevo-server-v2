<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_search_requires_authentication_and_a_complete_query(): void
    {
        $this->getJson('/api/admin/v1/search?q=operator')->assertForbidden();

        $admin = $this->adminWithRole('support');

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->getJson('/api/admin/v1/search?q=x')
            ->assertUnprocessable();
    }

    public function test_search_returns_only_authorized_groups_without_personal_contact_details(): void
    {
        $admin = $this->adminWithRole('support');
        User::factory()->create([
            'name' => 'Searchable Operator',
            'handle' => 'searchable-operator',
            'email' => 'private-contact@example.test',
            'phone' => '+234801234567',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->getJson('/api/admin/v1/search?q=Searchable');

        $response->assertOk()
            ->assertJsonPath('data.groups.0.label', 'Users')
            ->assertJsonPath('data.groups.0.items.0.title', 'Searchable Operator')
            ->assertJsonMissing(['email' => 'private-contact@example.test'])
            ->assertJsonMissing(['phone' => '+234801234567']);

        $groups = collect($response->json('data.groups'))->pluck('label');
        $this->assertFalse($groups->contains('Withdrawals'));
        $this->assertFalse($groups->contains('Creator payouts'));
    }

    private function adminWithRole(string $role): Admin
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create([
            'name' => 'Search Operator',
            'email' => Str::random(8).'@example.test',
            'password' => 'Admin-password-9!',
            'status' => 'active',
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_confirmed_at' => now(),
        ]);

        DB::table('admin_role')->insert([
            'admin_id' => $admin->id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        return $admin;
    }
}
