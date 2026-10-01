<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FoundingCreatorsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_founding_creator_sign_ups_can_be_reviewed_filtered_and_exported(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Creator Ops', 'email' => 'founding-ops@example.test', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'catalog_editor')->value('id')]);
        $first = $this->application(['name' => 'Chioma Obi', 'show_name' => 'Lagos After Dark', 'email' => 'chioma@example.test', 'publish_frequency' => 'weekly']);
        $this->application(['name' => 'Kwame Mensah', 'show_name' => '=Accra Talks', 'email' => 'kwame@example.test', 'publish_frequency' => 'monthly', 'state' => 'reviewing']);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $this->get('/admin/founding-creators')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/FoundingCreators')
            ->where('total', 2)
            ->where('applications.total', 2)
            ->where('counts.new', 1)
            ->where('counts.reviewing', 1)
            ->has('states', 5));
        $this->get('/admin/founding-creators?q=lagos')->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 1)
            ->where('applications.data.0.email', 'chioma@example.test'));
        $this->get('/admin/founding-creators?state=reviewing&frequency=monthly')->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 1)
            ->where('applications.data.0.email', 'kwame@example.test'));

        $this->putJson('/api/admin/v1/founding-creators/'.$first, ['state' => 'invited', 'admin_note' => 'Sent claim link by email.'])
            ->assertOk()
            ->assertJsonPath('data.audit_reference', fn ($value): bool => is_string($value) && $value !== '');
        $this->assertDatabaseHas('founding_creator_applications', ['id' => $first, 'state' => 'invited', 'admin_note' => 'Sent claim link by email.', 'reviewed_by_admin_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $first, 'action' => 'founding_creator.updated']);
        $this->putJson('/api/admin/v1/founding-creators/'.$first, ['state' => 'unknown'])->assertUnprocessable();

        $response = $this->get('/api/admin/v1/founding-creators/export');
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Podcast/show name', $csv);
        $this->assertStringContainsString('Lagos After Dark', $csv);
        $this->assertStringContainsString('Claim link sent', $csv);
        $this->assertStringContainsString("'=Accra Talks", $csv);
        $filtered = $this->get('/api/admin/v1/founding-creators/export?state=reviewing')->streamedContent();
        $this->assertStringNotContainsString('Lagos After Dark', $filtered);
        $this->assertDatabaseHas('audit_logs', ['action' => 'founding_creator.exported', 'admin_id' => $admin->id]);

        $this->getJson('/api/admin/v1/search?q=Lagos')->assertOk()->assertJsonFragment(['label' => 'Founding creators']);

        DB::table('admin_role')->where('admin_id', $admin->id)->delete();
        $this->get('/admin/founding-creators')->assertForbidden();
        $this->get('/api/admin/v1/founding-creators/export')->assertForbidden();
        $this->putJson('/api/admin/v1/founding-creators/'.$first, ['state' => 'declined'])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function application(array $overrides): string
    {
        $id = (string) Str::ulid();
        DB::table('founding_creator_applications')->insert([
            'id' => $id,
            'name' => 'Creator',
            'show_name' => 'Show',
            'show_url' => 'https://open.spotify.com/show/'.Str::random(8),
            'email' => Str::random(6).'@example.test',
            'state' => 'new',
            'submission_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }
}
