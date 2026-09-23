<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class IntegrationSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_operator_can_save_and_test_podcast_index_without_exposing_secrets(): void
    {
        $this->withoutVite();
        $this->operator();
        Http::fake(['https://api.podcastindex.org/api/1.0/*' => Http::response(['feeds' => [['id' => 1]]], 200)]);
        $this->get('/admin/settings/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations')
            ->has('integrations')
            ->where('integrations.0.provider', 'podcast_index'));
        $this->putJson('/api/admin/v1/integrations/podcast_index', [
            'reason' => 'Enable Podcast Index for catalog search in staging.',
            'values' => [
                'enabled' => '1',
                'base_url' => 'https://api.podcastindex.org/api/1.0',
                'api_key' => 'pi-key-secret-value',
                'api_secret' => 'pi-secret-value-xyz',
                'user_agent' => 'Pelevo-Test/1.0',
            ],
        ])->assertOk();
        $this->get('/admin/settings/integrations')->assertOk()->assertDontSee('pi-secret-value-xyz')->assertDontSee('pi-key-secret-value');
        $this->postJson('/api/admin/v1/integrations/podcast_index/test')->assertOk()->assertJsonPath('data.ok', true);
        $payload = json_decode(Crypt::decryptString(DB::table('integration_settings')->where('provider', 'podcast_index')->value('payload_encrypted')), true);
        $this->assertSame('pi-secret-value-xyz', $payload['api_secret']);
        $this->assertTrue((bool) config('services.podcast_index.enabled'));
    }

    public function test_smtp_probe_uses_mailer_and_mux_requires_token_pair(): void
    {
        $this->operator();
        Mail::fake();
        $this->putJson('/api/admin/v1/integrations/smtp', [
            'reason' => 'Point operator mail at the log mailer for local verification.',
            'values' => ['mailer' => 'array', 'host' => '127.0.0.1', 'port' => '2525', 'username' => 'pelevo', 'password' => 'mail-secret', 'scheme' => 'tls', 'from_address' => 'ops@pelevo.test', 'from_name' => 'Pelevo'],
        ])->assertOk();
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->postJson('/api/admin/v1/integrations/smtp/test')->assertOk()->assertJsonPath('data.ok', false);
        $this->postJson('/api/admin/v1/integrations/smtp/test', ['to' => 'ops@pelevo.test'])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'The array mailer does not send through Mailtrap. Set Mailer to smtp, save, then send the test again.');
        $this->postJson('/api/admin/v1/integrations/mux/test')->assertOk()->assertJsonPath('data.ok', false);
        Http::fake(['https://api.mux.com/*' => Http::response(['data' => []], 200)]);
        $this->putJson('/api/admin/v1/integrations/mux', [
            'reason' => 'Store Mux credentials for a later HLS cutover.',
            'values' => ['token_id' => 'mux-id', 'token_secret' => 'mux-secret', 'signing_key' => ''],
        ])->assertOk();
        $this->postJson('/api/admin/v1/integrations/mux/test')->assertOk()->assertJsonPath('data.ok', true);
        $this->get('/admin/settings/integrations')->assertDontSee('mux-secret');
        Http::fake(['https://api-m.paypal.com/*' => Http::response(['user_id' => 'paypal-user'], 200)]);
        $this->putJson('/api/admin/v1/integrations/paypal', [
            'reason' => 'Enable PayPal email payouts used by Earn and Creator Studio.',
            'values' => ['access_token' => 'paypal-access-secret', 'payout_url' => 'https://api-m.paypal.com/v1/payments/payouts', 'payout_verification_url' => ''],
        ])->assertOk();
        $this->postJson('/api/admin/v1/integrations/paypal/test')->assertOk()->assertJsonPath('data.ok', true);
        $this->get('/admin/settings/integrations')->assertSee('PayPal')->assertDontSee('paypal-access-secret');
        $this->assertSame('paypal-access-secret', config('services.paypal.payout_verification_token'));
    }

    private function operator(): Admin
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Operator', 'email' => Str::ulid().'@example.test', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'superadmin')->value('id')]);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);

        return $admin;
    }
}
