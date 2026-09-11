<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CanonicalDetailController extends Controller
{
    private const ENTITIES = [
        'shows' => ['table' => 'shows', 'title' => 'Show 360', 'permission' => 'catalog.write', 'parent' => '/admin/catalog'],
        'episodes' => ['table' => 'episodes', 'title' => 'Episode', 'permission' => 'catalog.write', 'parent' => '/admin/catalog'],
        'creators' => ['table' => 'creator_profiles', 'title' => 'Creator 360', 'permission' => 'claims.decide', 'parent' => '/admin/creators'],
        'reels' => ['table' => 'reels', 'title' => 'Reel review', 'permission' => 'moderation.act', 'parent' => '/admin/reels-live'],
        'transactions' => ['table' => 'ledger_transactions', 'title' => 'Financial transaction', 'permission' => 'finance.view', 'parent' => '/admin/finance'],
        'withdrawals' => ['table' => 'withdrawals', 'title' => 'Listener withdrawal', 'permission' => 'finance.view', 'parent' => '/admin/finance'],
        'payouts' => ['table' => 'creator_payouts', 'title' => 'Creator payout', 'permission' => 'finance.view', 'parent' => '/admin/finance'],
    ];

    private const COLUMNS = ['name', 'type', 'role', 'studio_id', 'reel_id', 'show_claim_id', 'existing_claim_id', 'body', 'is_pinned', 'hidden_at', 'edited_at', 'risk_state', 'verified_seconds', 'expected_award', 'locked_until', 'store', 'original_transaction_id', 'country_code', 'http_status', 'new_episode_count', 'started_at', 'finished_at', 'source_url', 'content', 'ends_at', 'expires_at', 'completed_at', 'source_type', 'source_id', 'balance', 'owner_type', 'owner_id', 'id', 'show_id', 'episode_id', 'creator_profile_id', 'user_id', 'title', 'display_name', 'author', 'language', 'status', 'state', 'availability', 'explicit', 'guid', 'external_id', 'provider', 'reference', 'provider_reference', 'event_type', 'idempotency_key', 'reverses_id', 'financial_account_id', 'ledger_transaction_id', 'creator_payout_batch_id', 'payout_method_id', 'fx_rate_version_id', 'fee_version_id', 'unit', 'currency', 'amount', 'amount_minor', 'coins', 'net_amount', 'gross_amount', 'fee_amount', 'base_unit', 'quote_currency', 'rate', 'basis_points', 'duration_seconds', 'duration_ms', 'caption', 'mime', 'width', 'height', 'processing_state', 'method', 'attempts', 'attempt', 'verified_at', 'destination_last_four', 'kind', 'label', 'compliance_state', 'schedule', 'minimum_amount', 'starts_at_seconds', 'format', 'rating', 'action', 'reason', 'decision_reason', 'field', 'old_value', 'new_value', 'consecutive_failures', 'last_success_at', 'last_failure_at', 'next_poll_at', 'difference', 'ledger_balance', 'projected_balance', 'prepared_by', 'approved_by', 'approved_at', 'period_start', 'period_end', 'published_at', 'effective_at', 'occurred_at', 'processed_at', 'detected_at', 'created_at', 'updated_at'];

    public function show(Request $request, string $entity, string $record): Response
    {
        $definition = self::ENTITIES[$entity] ?? null;
        abort_unless($definition, 404);
        $permissions = DB::table('admin_role')->join('permission_role', 'permission_role.role_id', '=', 'admin_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('admin_role.admin_id', $request->user('admin')->id)->pluck('permissions.name');
        abort_unless($permissions->contains($definition['permission']), 403);
        $row = DB::table($definition['table'])->find($record);
        abort_unless($row, 404);
        $sections = [];
        $add = function (string $label, string $table, string $key, string|array|null $value) use (&$sections): void {
            $sections[] = $this->section($label, $table, $key, $value);
        };
        if ($entity === 'shows') {
            $add('Episodes', 'episodes', 'show_id', $record);
            $add('RSS health', 'show_feed_states', 'show_id', $record);
            $add('Podcast Index', 'show_external_ids', 'show_id', $record);
            $add('Creator & claims', 'show_claims', 'show_id', $record);
            $add('Ownership disputes', 'claim_disputes', 'show_id', $record);
            $add('Followers', 'follows', 'show_id', $record);
            $add('Audience & ratings', 'show_ratings', 'show_id', $record);
            $add('Reels & live', 'reels', 'show_id', $record);
            $add('Live sessions', 'live_sessions', 'show_id', $record);
            $add('Metadata changes', 'show_metadata_changes', 'show_id', $record);
            $add('Sync runs', 'feed_sync_runs', 'show_id', $record);
        } elseif ($entity === 'episodes') {
            $add('Show', 'shows', 'id', $row->show_id);
            $add('Chapters', 'episode_chapters', 'episode_id', $record);
            $add('Transcripts', 'episode_transcripts', 'episode_id', $record);
            $add('Engagement', 'episode_reactions', 'episode_id', $record);
            $add('Comments', 'comments', 'commentable_id', $record);
            $add('Earn sessions', 'earn_sessions', 'episode_id', $record);
            $add('Earn awards', 'earn_awards', 'episode_id', $record);
            $add('Availability history', 'moderation_actions', 'subject_id', $record);
            $add('Premium eligibility', 'premium_exclusive_episodes', 'episode_id', $record);
            $add('Reel links', 'reel_episode_links', 'episode_id', $record);
        } elseif ($entity === 'creators') {
            $add('Studios', 'studios', 'creator_profile_id', $record);
            $add('Claims', 'show_claims', 'creator_profile_id', $record);
            $studioIds = DB::table('studios')->where('creator_profile_id', $record)->pluck('id')->all();
            $claimIds = DB::table('show_claims')->where('creator_profile_id', $record)->pluck('id')->all();
            $showIds = DB::table('show_claims')->join('verified_show_claims', 'verified_show_claims.show_claim_id', '=', 'show_claims.id')->where('show_claims.creator_profile_id', $record)->pluck('show_claims.show_id')->all();
            $add('Studio members', 'studio_members', 'studio_id', $studioIds);
            $add('Owned shows', 'shows', 'id', $showIds);
            $add('Disputes', 'claim_disputes', 'show_claim_id', $claimIds);
            $add('Strikes & sanctions', 'user_sanctions', 'user_id', $row->user_id);
            $add('Audience', 'creator_followers', 'creator_profile_id', $record);
            $add('Content', 'reels', 'creator_profile_id', $record);
            $add('Monetization', 'reel_monetization_profiles', 'creator_profile_id', $record);
            $add('Payout & tax', 'tax_profiles', 'creator_profile_id', $record);
            if ($permissions->contains('finance.view')) {
                $add('Payout settings', 'creator_payout_settings', 'creator_profile_id', $record);
                $add('Revenue', 'creator_revenue_events', 'creator_profile_id', $record);
                $add('Payouts', 'creator_payouts', 'creator_profile_id', $record);
            }
        } elseif ($entity === 'reels') {
            $add('Media & processing checks', 'reel_media', 'reel_id', $record);
            $add('Episode links', 'reel_episode_links', 'reel_id', $record);
            $add('Engagement', 'reel_engagements', 'reel_id', $record);
            $add('Comments', 'comments', 'commentable_id', $record);
            if ($permissions->contains('finance.view')) {
                $add('Revenue', 'creator_revenue_events', 'source_id', $record);
            }
            $add('Qualified views', 'reel_view_credits', 'reel_id', $record);
            $add('Processing timeline', 'reel_processing_events', 'reel_id', $record);
            $add('Moderation history', 'moderation_actions', 'subject_id', $record);
            $add('Reports', 'content_reports', 'reportable_id', $record);
            $add('Appeals', 'appeals', 'subject_id', $record);
        } else {
            $transaction = $entity === 'transactions' ? $record : $row->ledger_transaction_id;
            $add('Ledger transaction', 'ledger_transactions', 'id', $transaction);
            $add('Balanced ledger entries', 'ledger_entries', 'ledger_transaction_id', $transaction);
            $add('Reversals', 'ledger_transactions', 'reverses_id', $transaction);
            $transactionRow = $transaction ? DB::table('ledger_transactions')->find($transaction) : null;
            $metadata = $transactionRow ? json_decode($transactionRow->metadata ?? '{}', true) : [];
            $add('Applied fee version', 'fee_versions', 'id', $metadata['fee_version_id'] ?? null);
            if ($entity === 'transactions') {
                $add('Applied FX version', 'fx_rate_versions', 'id', $metadata['fx_rate_version_id'] ?? null);
                $add('Related withdrawals', 'withdrawals', 'ledger_transaction_id', $transaction);
                $add('IAP verification', 'iap_receipts', 'ledger_transaction_id', $transaction);
                $add('Gift source', 'gifts', 'ledger_transaction_id', $transaction);
                $add('Earn source', 'earn_awards', 'ledger_transaction_id', $transaction);
                $add('Related creator revenue', 'creator_revenue_events', 'ledger_transaction_id', $transaction);
                $add('Related payouts', 'creator_payouts', 'ledger_transaction_id', $transaction);
            }
            $balance = DB::table('ledger_entries')->where('ledger_transaction_id', $transaction)->selectRaw('unit, SUM(amount) as net, COUNT(*) as entries')->groupBy('unit')->get();
            $sections[] = ['label' => 'Balance validation', 'rows' => $balance, 'total' => $balance->count()];
            if ($entity !== 'transactions') {
                $add('Beneficiary verification', 'payout_methods', 'id', $row->payout_method_id);
                $add('Applied FX version', 'fx_rate_versions', 'id', $row->fx_rate_version_id);
                if ($entity === 'withdrawals') {
                    $add('Provider attempts', 'payout_attempts', 'withdrawal_id', $record);
                } else {
                    $add('Batch & approvals', 'creator_payout_batches', 'id', $row->creator_payout_batch_id);
                }
            }
            $accounts = DB::table('ledger_entries')->where('ledger_transaction_id', $transaction)->pluck('financial_account_id');
            $add('Accounts & wallets', 'financial_accounts', 'id', $accounts->all());
            $sections[] = ['label' => 'Reconciliation', 'rows' => DB::table('reconciliation_items')->whereIn('financial_account_id', $accounts)->select('id', 'state', 'difference', 'financial_account_id', 'created_at')->latest()->limit(100)->get(), 'total' => DB::table('reconciliation_items')->whereIn('financial_account_id', $accounts)->count()];
        }
        $add('Audit history', 'audit_logs', 'subject_id', $record);

        $media = $row->media_url ?? $row->audio_url ?? null;
        $safeMedia = $media && in_array(parse_url($media, PHP_URL_SCHEME), ['http', 'https'], true) && ! parse_url($media, PHP_URL_USER) ? $media : null;

        return Inertia::render('Admin/CanonicalDetail', ['entity' => $entity, 'heading' => $definition['title'], 'parent' => $definition['parent'], 'record' => array_intersect_key((array) $row, array_flip(self::COLUMNS)), 'sections' => $sections, 'mediaUrl' => $safeMedia, 'freshAt' => now()->toIso8601String()]);
    }

    private function section(string $label, string $table, string $key, string|array|null $value): array
    {
        $columns = array_values(array_intersect(self::COLUMNS, Schema::getColumnListing($table)));
        $query = DB::table($table);
        if (is_array($value)) {
            $query->whereIn($key, $value);
        } else {
            $query->where($key, $value);
        }
        if (in_array('created_at', $columns, true)) {
            $query->orderBy('created_at');
        }
        if ($value === null) {
            $query->whereRaw('1 = 0');
        }

        $page = $query->select($columns)->paginate($table === 'episode_transcripts' ? 1 : 50, pageName: Str::slug($label).'_page')->withQueryString();

        return ['label' => $label, 'rows' => $page->items(), 'total' => $page->total(), 'next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()];
    }
}
