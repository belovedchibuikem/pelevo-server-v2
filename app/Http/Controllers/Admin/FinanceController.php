<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Finance\ReverseLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\RunReconciliation;
use App\Mail\PelevoNotice;
use App\Models\ConfigurationVersion;
use App\Services\MailPreference;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceController extends Controller
{
    public function page(Request $request): Response
    {
        $desk = $request->validate(['desk' => ['nullable', 'in:fx,iap,earn,withdrawals,payouts,premium,ledger']])['desk'] ?? 'fx';

        return Inertia::render('Admin/Finance', [...$this->workspace(), 'desk' => $desk]);
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->workspace());
    }

    public function transaction(string $transaction): JsonResponse
    {
        $row = DB::table('ledger_transactions')->where('id', $transaction)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Ledger transaction not found.', 404);
        }

        return ApiResponse::success(['transaction' => $row, 'entries' => DB::table('ledger_entries')->join('financial_accounts', 'financial_accounts.id', '=', 'ledger_entries.financial_account_id')->where('ledger_transaction_id', $transaction)->select('ledger_entries.*', 'financial_accounts.type as account_type')->orderBy('ledger_entries.id')->get()]);
    }

    public function reverse(string $transaction, Request $request, ReverseLedgerTransaction $reverse): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $result = $reverse->handle($transaction, 'admin-reversal:'.(string) Str::ulid(), $data['reason']);
        $this->audit($request, 'ledger.reversed', 'App\\Models\\LedgerTransaction', $transaction, $data['reason'], ['reversal_id' => $result->id]);

        return ApiResponse::success(['reversal_id' => $result->id, 'reference' => $result->reference]);
    }

    public function withdrawal(string $withdrawal, Request $request, ReverseLedgerTransaction $reverse): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'in:approved,processing,paid,failed,rejected,reversed'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'provider_reference' => ['nullable', 'string', 'max:191']]);

        return DB::transaction(function () use ($withdrawal, $request, $data, $reverse): JsonResponse {
            $row = DB::table('withdrawals')->where('id', $withdrawal)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Withdrawal not found.', 404);
            }
            $allowed = ['queued' => ['approved', 'rejected'], 'approved' => ['processing', 'rejected'], 'processing' => ['paid', 'failed'], 'paid' => ['reversed'], 'failed' => [], 'rejected' => [], 'reversed' => []];
            if (! in_array($data['state'], $allowed[$row->state] ?? [], true)) {
                return ApiResponse::error('CONFLICT', 'Invalid payout state transition.', 409);
            }
            if (in_array($data['state'], ['failed', 'rejected', 'reversed'], true)) {
                $reverse->handle($row->ledger_transaction_id, 'withdrawal-release:'.$row->id, $data['reason']);
            }
            DB::table('withdrawals')->where('id', $withdrawal)->update(['state' => $data['state'], 'provider_reference' => $data['provider_reference'] ?? $row->provider_reference, 'failure_reason' => in_array($data['state'], ['failed', 'rejected'], true) ? $data['reason'] : null, 'processed_at' => in_array($data['state'], ['paid', 'failed', 'rejected', 'reversed'], true) ? now() : null, 'updated_at' => now()]);
            if ($data['state'] === 'approved') {
                DispatchWithdrawal::dispatch($withdrawal)->afterCommit();
            }
            if (in_array($data['state'], ['paid', 'failed', 'rejected'], true)) {
                $this->notifyWithdrawal((string) $row->user_id, $data['state'], (int) $row->coins, $data['reason']);
            }
            $this->audit($request, 'withdrawal.'.$data['state'], 'App\\Models\\Withdrawal', $withdrawal, $data['reason'], ['from' => $row->state, 'to' => $data['state']]);

            return ApiResponse::success(['withdrawal_id' => $withdrawal, 'state' => $data['state']]);
        }, 3);
    }

    public function earn(string $session, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:clear,rejected'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $updated = DB::table('earn_sessions')->where('id', $session)->where('risk_state', 'review')->update(['risk_state' => $data['decision'] === 'clear' ? 'clear' : 'rejected', 'state' => $data['decision'] === 'clear' ? 'active' : 'rejected', 'active_guard' => $data['decision'] === 'clear' ? 'active' : null, 'nonce_hash' => $data['decision'] === 'clear' ? DB::raw('nonce_hash') : null, 'updated_at' => now()]);
        if (! $updated) {
            return ApiResponse::error('NOT_FOUND', 'Earn review session not found.', 404);
        }
        $this->audit($request, 'earn.'.$data['decision'], 'App\\Models\\EarnSession', $session, $data['reason'], ['decision' => $data['decision']]);

        return ApiResponse::success(['session_id' => $session, 'state' => $data['decision']]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate(['business_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        RunReconciliation::dispatchSync($data['business_date']);
        $this->audit($request, 'reconciliation.run', 'App\\Models\\ReconciliationRun', $data['business_date'], 'Manual reconciliation run.', $data);

        return ApiResponse::success(DB::table('reconciliation_runs')->where('business_date', $data['business_date'])->first());
    }

    public function importSettlement(Request $request): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', 'string', 'max:50'], 'provider_settlement_id' => ['required', 'string', 'max:191'], 'business_date' => ['required', 'date_format:Y-m-d'], 'currency' => ['required', 'string', 'size:3'], 'gross_minor' => ['required', 'integer'], 'fees_minor' => ['required', 'integer', 'min:0'], 'net_minor' => ['required', 'integer']]);
        if ($data['net_minor'] !== $data['gross_minor'] - $data['fees_minor']) {
            return ApiResponse::error('VALIDATION', 'Settlement gross less fees must equal net.', 422);
        }
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $existing = DB::table('provider_settlements')->where('provider', $data['provider'])->where('provider_settlement_id', $data['provider_settlement_id'])->first();
        if ($existing) {
            return hash_equals($existing->payload_hash, $hash) ? ApiResponse::success($existing) : ApiResponse::error('CONFLICT', 'Settlement reference was replayed with different values.', 409);
        }
        $id = (string) Str::ulid();
        DB::table('provider_settlements')->insert([...$data, 'id' => $id, 'currency' => strtoupper($data['currency']), 'state' => 'imported', 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'settlement.imported', 'App\\Models\\ProviderSettlement', $id, 'Provider settlement import.', ['payload_hash' => $hash]);

        return ApiResponse::success(DB::table('provider_settlements')->find($id), status: 201);
    }

    public function resolveException(string $exception, Request $request): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'min:10', 'max:2000']]);
        $updated = DB::table('finance_exceptions')->where('id', $exception)->where('state', 'open')->update(['state' => 'resolved', 'resolution' => $data['resolution'], 'resolved_by' => auth('admin')->id(), 'resolved_at' => now(), 'updated_at' => now()]);
        if (! $updated) {
            return ApiResponse::error('NOT_FOUND', 'Open finance exception not found.', 404);
        }
        $this->audit($request, 'finance_exception.resolved', 'App\\Models\\FinanceException', $exception, $data['resolution'], ['state' => 'resolved']);

        return ApiResponse::success(['id' => $exception, 'state' => 'resolved']);
    }

    public function creatorCompliance(string $creator, Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'in:verified,rejected'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $updated = DB::transaction(function () use ($creator, $data): int {
            DB::table('tax_profiles')->where('creator_profile_id', $creator)->update(['state' => $data['state'], 'updated_at' => now()]);

            return DB::table('creator_payout_settings')->where('creator_profile_id', $creator)->update(['compliance_state' => $data['state'], 'verified_at' => $data['state'] === 'verified' ? now() : null, 'updated_at' => now()]);
        });
        if (! $updated) {
            return ApiResponse::error('NOT_FOUND', 'Creator payout settings not found.', 404);
        }
        $this->audit($request, 'creator_payout_settings.'.$data['state'], 'App\\Models\\CreatorProfile', $creator, $data['reason'], ['compliance_state' => $data['state']]);

        return ApiResponse::success(DB::table('creator_payout_settings')->where('creator_profile_id', $creator)->first());
    }

    public function feeVersion(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:gift_platform,reel_platform'], 'percent' => ['required', 'numeric', 'min:0', 'max:100'], 'effective_at' => ['required', 'date'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $effectiveAt = CarbonImmutable::parse($data['effective_at']);
        $basisPoints = (int) round(((float) $data['percent']) * 100);
        if (DB::table('fee_versions')->where('type', $data['type'])->where('effective_at', $effectiveAt)->exists()) {
            return ApiResponse::error('CONFLICT', 'A fee version already exists for this type and effective time. Choose a later effective time.', 409);
        }
        $id = (string) Str::ulid();
        DB::table('fee_versions')->insert(['id' => $id, 'type' => $data['type'], 'basis_points' => $basisPoints, 'effective_at' => $effectiveAt, 'active' => true, 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'fee_version.published', 'App\\Models\\FeeVersion', $id, $data['reason'], ['type' => $data['type'], 'basis_points' => $basisPoints, 'effective_at' => $effectiveAt->toIso8601String()]);

        return ApiResponse::success(DB::table('fee_versions')->find($id), status: 201);
    }

    public function fxVersion(Request $request): JsonResponse
    {
        $data = $request->validate(['base_unit' => ['required', 'string', 'size:3', 'alpha'], 'quote_currency' => ['required', 'string', 'size:3', 'alpha'], 'rate' => ['required', 'numeric', 'gt:0'], 'source' => ['required', 'string', 'max:80'], 'effective_at' => ['required', 'date'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $effectiveAt = CarbonImmutable::parse($data['effective_at']);
        $base = strtoupper($data['base_unit']);
        $quote = strtoupper($data['quote_currency']);
        if (DB::table('fx_rate_versions')->where('base_unit', $base)->where('quote_currency', $quote)->where('effective_at', $effectiveAt)->exists()) {
            return ApiResponse::error('CONFLICT', 'An FX version already exists for this pair and effective time.', 409);
        }
        $id = (string) Str::ulid();
        DB::table('fx_rate_versions')->insert(['id' => $id, 'base_unit' => $base, 'quote_currency' => $quote, 'rate' => $data['rate'], 'source' => $data['source'], 'effective_at' => $effectiveAt, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'fx_version.published', 'App\\Models\\FxRateVersion', $id, $data['reason'], ['base_unit' => $base, 'quote_currency' => $quote, 'rate' => $data['rate'], 'effective_at' => $effectiveAt->toIso8601String()]);

        return ApiResponse::success(DB::table('fx_rate_versions')->find($id), status: 201);
    }

    public function coinProduct(Request $request): JsonResponse
    {
        $data = $request->validate(['store' => ['required', 'in:apple,google'], 'product_id' => ['required', 'string', 'max:191'], 'coins' => ['required', 'integer', 'min:1'], 'unit' => ['nullable', 'in:PCN'], 'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $existing = DB::table('coin_products')->where('store', $data['store'])->where('product_id', $data['product_id'])->first();
        if ($existing) {
            if (DB::table('iap_receipts')->where('coin_product_id', $existing->id)->exists() && (int) $existing->coins !== (int) $data['coins']) {
                return ApiResponse::error('CONFLICT', 'This store SKU already has receipts. Retire it and publish a new product id instead of changing the coin value.', 409);
            }
            DB::table('coin_products')->where('id', $existing->id)->update(['coins' => $data['coins'], 'unit' => $data['unit'] ?? 'PCN', 'active' => $data['active'], 'updated_at' => now()]);
            $this->audit($request, 'coin_product.updated', 'App\\Models\\CoinProduct', $existing->id, $data['reason'], ['coins' => $data['coins'], 'active' => $data['active']]);

            return ApiResponse::success(DB::table('coin_products')->find($existing->id));
        }
        $id = (string) Str::ulid();
        DB::table('coin_products')->insert(['id' => $id, 'store' => $data['store'], 'product_id' => $data['product_id'], 'coins' => $data['coins'], 'unit' => $data['unit'] ?? 'PCN', 'active' => $data['active'], 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'coin_product.published', 'App\\Models\\CoinProduct', $id, $data['reason'], ['store' => $data['store'], 'product_id' => $data['product_id'], 'coins' => $data['coins']]);

        return ApiResponse::success(DB::table('coin_products')->find($id), status: 201);
    }

    public function coinProductState(string $product, Request $request): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $updated = DB::table('coin_products')->where('id', $product)->update(['active' => $data['active'], 'updated_at' => now()]);
        if (! $updated) {
            return ApiResponse::error('NOT_FOUND', 'Coin product not found.', 404);
        }
        $this->audit($request, $data['active'] ? 'coin_product.restored' : 'coin_product.retired', 'App\\Models\\CoinProduct', $product, $data['reason'], ['active' => $data['active']]);

        return ApiResponse::success(DB::table('coin_products')->find($product));
    }

    public function giftType(Request $request): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'alpha_dash', 'max:80', 'unique:gift_types,slug'], 'name' => ['required', 'string', 'max:191'], 'coins' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $id = (string) Str::ulid();
        DB::table('gift_types')->insert(['id' => $id, 'slug' => $data['slug'], 'name' => $data['name'], 'coins' => $data['coins'], 'active' => $data['active'], 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'gift_type.published', 'App\\Models\\GiftType', $id, $data['reason'], ['slug' => $data['slug'], 'coins' => $data['coins']]);

        return ApiResponse::success(DB::table('gift_types')->find($id), status: 201);
    }

    public function giftTypeUpdate(string $giftType, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191'], 'coins' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $row = DB::table('gift_types')->where('id', $giftType)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Gift type not found.', 404);
        }
        DB::table('gift_types')->where('id', $giftType)->update(['name' => $data['name'], 'coins' => $data['coins'], 'active' => $data['active'], 'version' => $row->version + 1, 'updated_at' => now()]);
        $this->audit($request, 'gift_type.versioned', 'App\\Models\\GiftType', $giftType, $data['reason'], ['from_version' => $row->version, 'to_version' => $row->version + 1, 'coins' => $data['coins'], 'active' => $data['active']]);

        return ApiResponse::success(DB::table('gift_types')->find($giftType));
    }

    public function premiumPlan(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'exists:premium_plans,id'], 'slug' => ['required', 'alpha_dash', 'max:80'], 'name' => ['required', 'string', 'max:191'], 'price_minor' => ['required', 'integer', 'min:1'], 'currency' => ['required', 'string', 'size:3'], 'interval' => ['required', 'in:month,year'], 'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $id = $data['id'] ?? (string) Str::ulid();
        $values = ['slug' => $data['slug'], 'name' => $data['name'], 'price_minor' => $data['price_minor'], 'currency' => strtoupper($data['currency']), 'interval' => $data['interval'], 'active' => $data['active'], 'updated_at' => now()];
        if (isset($data['id'])) {
            DB::table('premium_plans')->where('id', $id)->update($values);
        } else {
            DB::table('premium_plans')->insert([...$values, 'id' => $id, 'created_at' => now()]);
        }
        $this->audit($request, 'premium_plan.saved', 'App\\Models\\PremiumPlan', $id, $data['reason'], $values);

        return ApiResponse::success(DB::table('premium_plans')->find($id), status: isset($data['id']) ? 200 : 201);
    }

    private function workspace(): array
    {
        $unmatchedReceiptCount = DB::table('iap_receipts')->whereNotIn('state', ['verified', 'credited'])->count();
        $earnReviewCount = DB::table('earn_sessions')->where('risk_state', 'review')->count();
        $queuedWithdrawalCount = DB::table('withdrawals')->where('state', 'queued')->count();
        $unmatchedReceipts = DB::table('iap_receipts')->whereNotIn('state', ['verified', 'credited'])->latest()->limit(50)->get();
        $earnReview = DB::table('earn_sessions')->where('risk_state', 'review')->latest()->limit(50)->get();
        $queuedWithdrawals = DB::table('withdrawals')->where('state', 'queued')->latest()->limit(50)->get();
        $openExceptions = DB::table('finance_exceptions')->where('state', 'open')->latest()->limit(50)->get();
        $draftBatches = DB::table('creator_payout_batches')->where('state', 'draft')->count();
        $openDrift = (int) DB::table('reconciliation_items')->where('state', 'open')->count();
        $attention = [
            ['label' => 'Queued withdrawals', 'count' => $queuedWithdrawalCount, 'desk' => 'withdrawals', 'severity' => 'warning'],
            ['label' => 'Earn fraud review', 'count' => $earnReviewCount, 'desk' => 'earn', 'severity' => 'warning'],
            ['label' => 'Unmatched IAP receipts', 'count' => $unmatchedReceiptCount, 'desk' => 'iap', 'severity' => 'warning'],
            ['label' => 'Reconciliation drift', 'count' => $openDrift, 'desk' => 'ledger', 'severity' => 'warning'],
            ['label' => 'Draft payout batches', 'count' => $draftBatches, 'desk' => 'payouts', 'severity' => 'info'],
        ];
        $desks = [
            ['key' => 'fx', 'title' => 'FX & products', 'blurb' => 'Coin packs, gifts, fees, FX, and thresholds', 'count' => DB::table('coin_products')->count() + DB::table('gift_types')->count()],
            ['key' => 'iap', 'title' => 'IAP', 'blurb' => 'Unmatched receipts and verify failures', 'count' => $unmatchedReceiptCount],
            ['key' => 'earn', 'title' => 'Earn liability', 'blurb' => 'Outstanding coins, daily issuance, farms', 'count' => $earnReviewCount],
            ['key' => 'withdrawals', 'title' => 'Listener withdrawals', 'blurb' => 'Queue, paid, failed, gateway status', 'count' => DB::table('withdrawals')->whereIn('state', ['queued', 'processing'])->count()],
            ['key' => 'payouts', 'title' => 'Creator payouts', 'blurb' => 'Monthly batch, hold, maker-checker', 'count' => DB::table('creator_payout_batches')->whereIn('state', ['draft', 'approved'])->count()],
            ['key' => 'premium', 'title' => 'Premium', 'blurb' => 'MRR, churn, failed charges, refunds', 'count' => DB::table('premium_subscriptions')->where('state', 'active')->count()],
            ['key' => 'ledger', 'title' => 'Ledger explorer', 'blurb' => 'Immutable credits and reversing entries', 'count' => DB::table('ledger_transactions')->count()],
        ];

        return [
            'desks' => $desks,
            'attention' => $attention,
            'products' => [
                'fees' => DB::table('fee_versions')->latest('effective_at')->limit(40)->get(),
                'fx' => DB::table('fx_rate_versions')->latest('effective_at')->limit(40)->get(),
                'gifts' => DB::table('gift_types')->latest()->limit(50)->get(),
                'packs' => DB::table('coin_products')->orderBy('store')->orderBy('coins')->limit(50)->get(),
                'minWithdrawCoins' => (int) data_get(ConfigurationVersion::query()->where('effective_at', '<=', now())->latest('version')->first()?->payload, 'money.earn_min_withdraw_coins', config('finance.earn_min_withdraw_coins')),
                'dailyEarnCap' => (int) config('finance.earn_daily_completion_cap'),
                'reelQualifiedViewPcn' => (int) config('finance.reel_qualified_view_pcn'),
            ],
            'iap' => ['unmatched' => $unmatchedReceipts],
            'earn' => [
                'liability' => (int) DB::table('financial_accounts')->where('type', 'earn_wallet')->sum('balance'),
                'issuedToday' => (int) DB::table('earn_awards')->where('created_at', '>=', now()->startOfDay())->sum('coins'),
                'review' => $earnReview,
                'campaigns' => DB::table('earn_campaigns')->latest()->limit(20)->get(),
            ],
            'withdrawals' => [
                'queued' => $queuedWithdrawals,
                'processing' => DB::table('withdrawals')->whereIn('state', ['approved', 'processing'])->latest()->limit(50)->get(),
                'failed' => DB::table('withdrawals')->where('state', 'failed')->latest()->limit(50)->get(),
            ],
            'payouts' => [
                'batches' => DB::table('creator_payout_batches')->latest()->limit(50)->get(),
                'revenue' => DB::table('creator_revenue_events')->latest('occurred_at')->limit(30)->get(),
            ],
            'premium' => [
                'mrrMinor' => (int) DB::table('premium_subscriptions')->join('premium_plans', 'premium_plans.id', '=', 'premium_subscriptions.premium_plan_id')->where('premium_subscriptions.state', 'active')->selectRaw("COALESCE(SUM(CASE WHEN premium_plans.interval = 'year' THEN FLOOR(premium_plans.price_minor / 12) ELSE premium_plans.price_minor END), 0) as mrr")->value('mrr'),
                'active' => DB::table('premium_subscriptions')->where('state', 'active')->count(),
                'churnedThisMonth' => DB::table('premium_subscriptions')->whereNotNull('cancel_at')->where('cancel_at', '>=', now()->startOfMonth())->count(),
                'failedInvoices' => DB::table('invoices')->whereIn('state', ['failed', 'unpaid', 'past_due'])->count(),
                'refunds' => DB::table('premium_refunds')->count(),
                'plans' => DB::table('premium_plans')->orderBy('price_minor')->get(),
                'subscriptions' => DB::table('premium_subscriptions')->latest()->limit(30)->get(),
                'invoices' => DB::table('invoices')->latest()->limit(30)->get(),
            ],
            'ledger' => [
                'accounts' => DB::table('financial_accounts')->orderBy('unit')->orderBy('type')->limit(80)->get(),
                'transactions' => DB::table('ledger_transactions')->latest()->limit(40)->get(),
            ],
            'exceptions' => $openExceptions,
            'reconciliation' => DB::table('reconciliation_runs')->latest('business_date')->limit(20)->get(),
            'settlements' => DB::table('provider_settlements')->latest('business_date')->limit(20)->get(),
            'freshAt' => now()->toIso8601String(),
        ];
    }

    private function notifyWithdrawal(string $userId, string $state, int $coins, string $reason): void
    {
        $paid = $state === 'paid';
        app(MailPreference::class)->queueToUser($userId, new PelevoNotice(
            subjectLine: $paid ? 'Your Pelevo withdrawal was paid' : 'Your Pelevo withdrawal did not go through',
            eyebrow: 'Wallet',
            heading: $paid ? 'Withdrawal paid' : 'Withdrawal '.$state,
            intro: $paid
                ? 'We sent your withdrawal of '.$coins.' coins.'
                : 'Your withdrawal of '.$coins.' coins was marked '.$state.'.',
            detail: $paid ? null : $reason,
        ));
    }

    private function audit(Request $request, string $action, string $type, string $id, string $reason, array $after): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => auth('admin')->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'reason' => $reason, 'after' => json_encode($after), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
