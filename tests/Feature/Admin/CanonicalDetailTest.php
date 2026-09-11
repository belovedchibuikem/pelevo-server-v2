<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CreatorProfile;
use App\Models\Episode;
use App\Models\FinancialAccount;
use App\Models\LedgerTransaction;
use App\Models\Show;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CanonicalDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalog_and_creator_detail_routes_have_scoped_evidence_and_enforce_roles(): void
    {
        $this->withoutVite();
        $admin = $this->operator();
        $show = Show::create(['rss_url' => 'https://publisher.example/detail.xml', 'title' => 'Detailed show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'canonical-episode', 'title' => 'Detailed episode', 'audio_url' => 'https://publisher.example/episode.mp3']);
        $creator = CreatorProfile::create(['user_id' => User::factory()->create()->id, 'display_name' => 'Creator']);
        foreach (['shows' => $show->id, 'episodes' => $episode->id, 'creators' => $creator->id] as $entity => $id) {
            $this->get('/admin/records/'.$entity.'/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/CanonicalDetail')->where('record.id', $id)->has('sections')->missing('record.password'));
        }
        DB::table('admin_role')->where('admin_id', $admin->id)->delete();
        $this->get('/admin/records/shows/'.$show->id)->assertForbidden();
    }

    public function test_financial_and_reel_details_expose_evidence_without_provider_secrets(): void
    {
        $this->withoutVite();
        $admin = $this->operator();
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Financial creator']);
        $transaction = LedgerTransaction::create(['reference' => 'detail-finance', 'event_type' => 'withdrawal', 'idempotency_key' => 'detail-key', 'metadata' => ['provider_secret' => 'never-expose-me']]);
        foreach ([-100, 100] as $amount) {
            $account = FinancialAccount::create(['owner_type' => User::class, 'owner_id' => $user->id, 'type' => $amount < 0 ? 'available' : 'reserved', 'unit' => 'PCN']);
            $this->insert('ledger_entries', ['ledger_transaction_id' => $transaction->id, 'financial_account_id' => $account->id, 'amount' => $amount, 'unit' => 'PCN']);
        }
        $method = $this->insert('payout_methods', ['owner_type' => User::class, 'owner_id' => $user->id, 'provider' => 'test', 'destination_encrypted' => 'never-expose-me', 'destination_last_four' => '4321']);
        $withdrawal = $this->insert('withdrawals', ['user_id' => $user->id, 'payout_method_id' => $method, 'ledger_transaction_id' => $transaction->id, 'coins' => 100, 'idempotency_key' => 'withdrawal-detail']);
        $batch = $this->insert('creator_payout_batches', ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'unit' => 'PCN', 'idempotency_key' => 'detail-batch', 'prepared_by' => $admin->id, 'reason' => 'Review payout evidence']);
        $payout = $this->insert('creator_payouts', ['creator_payout_batch_id' => $batch, 'creator_profile_id' => $creator->id, 'payout_method_id' => $method, 'unit' => 'PCN', 'amount' => 100, 'currency' => 'USD', 'amount_minor' => 100, 'idempotency_key' => 'detail-payout']);
        $reel = $this->insert('reels', ['creator_profile_id' => $creator->id, 'caption' => 'Review reel', 'media_url' => 'javascript:alert(1)']);
        foreach (['transactions' => $transaction->id, 'withdrawals' => $withdrawal, 'payouts' => $payout, 'reels' => $reel] as $entity => $id) {
            $this->get('/admin/records/'.$entity.'/'.$id)->assertOk()->assertDontSee('never-expose-me')->assertInertia(fn (Assert $page) => $page->component('Admin/CanonicalDetail')->where('record.id', $id)->missing('record.metadata')->missing('record.destination_encrypted')->where('mediaUrl', null));
        }
        $this->get('/admin/records/transactions/'.$transaction->id)->assertInertia(fn (Assert $page) => $page->where('sections', function ($sections): bool {
            $balance = collect($sections)->firstWhere('label', 'Balance validation');

            return (int) $balance['rows'][0]['net'] === 0 && $balance['rows'][0]['entries'] === 2;
        }));
        DB::table('admin_role')->where('admin_id', $admin->id)->delete();
        $this->get('/admin/records/transactions/'.$transaction->id)->assertForbidden();
    }

    private function insert(string $table, array $values): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert(['id' => $id, ...$values, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
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
