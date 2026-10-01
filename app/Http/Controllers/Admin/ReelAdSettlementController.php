<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Finance\PostLedgerTransaction;
use App\Actions\Finance\ReverseLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Jobs\AllocateReelAdRevenue;
use App\Jobs\ReleaseReconciledReelAdRevenue;
use App\Models\FinancialAccount;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReelAdSettlementController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'batches' => DB::table('admob_reconciliation_batches')->latest('statement_month')->limit(36)->get(),
            'pending_usd_micros' => (int) DB::table('creator_ad_revenue_allocations')->where('status', 'pending')->sum('gross_usd_micros'),
            'confirmed_usd_micros' => (int) DB::table('financial_accounts')->where('type', 'reels_ad_confirmed')->where('unit', 'USD')->sum('balance'),
            'usd_reserve_micros' => (int) DB::table('financial_accounts')->where('type', 'reels_ad_reserve')->where('unit', 'USD')->sum('balance'),
            'available' => DB::table('financial_accounts')
                ->where('type', 'reels_ad_available')
                ->whereIn('unit', ['USD', 'NGN'])
                ->groupBy('unit')
                ->select('unit', DB::raw('sum(balance) as balance'))
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admob_account_id' => ['required', 'string', 'max:191'],
            'statement_month' => ['required', 'date_format:Y-m'],
            'finalized_usd_micros' => ['required', 'integer', 'min:0'],
            'estimated_usd_micros' => ['nullable', 'integer', 'min:0'],
            'source_checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
            'source_reference' => ['nullable', 'string', 'max:191'],
            'timezone' => ['nullable', 'timezone'],
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $month = $data['statement_month'].'-01';
        $existing = DB::table('admob_reconciliation_batches')
            ->where('admob_account_id', $data['admob_account_id'])
            ->where('statement_month', $month)
            ->first();
        if ($existing) {
            $matches = hash_equals($existing->source_checksum, strtolower($data['source_checksum']))
                && (int) $existing->finalized_usd_micros === (int) $data['finalized_usd_micros'];

            return $matches
                ? ApiResponse::success($existing)
                : ApiResponse::error('CONFLICT', 'This AdMob statement month was already imported with different values.', 409);
        }

        $id = (string) Str::ulid();
        $estimated = $data['estimated_usd_micros'] ?? null;
        DB::table('admob_reconciliation_batches')->insert([
            'id' => $id,
            'admob_account_id' => $data['admob_account_id'],
            'statement_month' => $month,
            'timezone' => $data['timezone'] ?? config('reels_ads.settlement_timezone'),
            'currency' => 'USD',
            'finalized_usd_micros' => $data['finalized_usd_micros'],
            'estimated_usd_micros' => $estimated,
            'adjustment_usd_micros' => $estimated === null ? 0 : (int) $data['finalized_usd_micros'] - (int) $estimated,
            'source_checksum' => strtolower($data['source_checksum']),
            'source_reference' => $data['source_reference'] ?? null,
            'status' => 'draft',
            'imported_by' => auth('admin')->id(),
            'notes' => $data['notes'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'reels_ad_settlement.imported', $id, $data['notes'], [
            'statement_month' => $month,
            'finalized_usd_micros' => $data['finalized_usd_micros'],
            'source_checksum' => strtolower($data['source_checksum']),
        ]);

        return ApiResponse::success(DB::table('admob_reconciliation_batches')->find($id), status: 201);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statement_month' => ['required', 'date_format:Y-m'],
            'finalized_usd_micros' => ['required', 'integer', 'min:0'],
            'timezone' => ['nullable', 'timezone'],
        ]);
        $timezone = $data['timezone'] ?? config('reels_ads.settlement_timezone');
        $start = CarbonImmutable::parse($data['statement_month'].'-01', $timezone)->startOfMonth()->utc();
        $end = $start->addMonth();
        $query = DB::table('creator_ad_revenue_allocations')
            ->join('ad_impressions', 'ad_impressions.id', '=', 'creator_ad_revenue_allocations.ad_impression_id')
            ->whereIn('creator_ad_revenue_allocations.status', ['pending', 'confirmed'])
            ->where('ad_impressions.watched_at', '>=', $start)
            ->where('ad_impressions.watched_at', '<', $end);
        $gross = (int) (clone $query)->sum('creator_ad_revenue_allocations.gross_usd_micros');
        $maximum = min($gross, (int) $data['finalized_usd_micros']);

        return ApiResponse::success([
            'statement_month' => $data['statement_month'],
            'timezone' => $timezone,
            'allocation_count' => (clone $query)->count(),
            'gross_pending_usd_micros' => $gross,
            'maximum_confirmable_usd_micros' => $maximum,
            'scale_ratio' => $gross === 0 ? '0.00000000' : number_format($maximum / $gross, 8, '.', ''),
            'fraud_review_count' => (clone $query)->where('ad_impressions.fraud_state', 'review')->count(),
            'hold_eligible_count' => (clone $query)->where('creator_ad_revenue_allocations.held_until', '<=', now())->count(),
        ]);
    }

    public function approve(string $batch, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($batch, $request, $data): JsonResponse {
            $row = DB::table('admob_reconciliation_batches')->where('id', $batch)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'AdMob reconciliation batch not found.', 404);
            }
            if ($row->imported_by === auth('admin')->id()) {
                return ApiResponse::error('MAKER_CHECKER_REQUIRED', 'A different finance administrator must approve this statement.', 409);
            }
            if ($row->status !== 'draft') {
                return ApiResponse::error('CONFLICT', 'Only draft AdMob statements can be approved.', 409);
            }
            DB::table('admob_reconciliation_batches')->where('id', $batch)->update([
                'status' => 'approved',
                'approved_by' => auth('admin')->id(),
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'reels_ad_settlement.approved', $batch, $data['reason'], [
                'statement_month' => $row->statement_month,
                'finalized_usd_micros' => $row->finalized_usd_micros,
            ]);
            DB::afterCommit(fn () => ReleaseReconciledReelAdRevenue::dispatch($batch));

            return ApiResponse::success(DB::table('admob_reconciliation_batches')->find($batch));
        }, 3);
    }

    public function reviewImpression(
        string $impression,
        Request $request,
        ReverseLedgerTransaction $reverse,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:clear,reject'],
            'reason_code' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return DB::transaction(function () use ($impression, $request, $reverse, $data): JsonResponse {
            $row = DB::table('ad_impressions')->where('id', $impression)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Ad impression not found.', 404);
            }
            if ($data['decision'] === 'clear') {
                $attributions = DB::table('ad_impression_attributions')
                    ->where('ad_impression_id', $impression);
                $hasSelfAttribution = (clone $attributions)
                    ->join('creator_profiles', 'creator_profiles.id', '=', 'ad_impression_attributions.creator_profile_id')
                    ->where('creator_profiles.user_id', $row->user_id)
                    ->exists();
                if ((clone $attributions)->count() !== (int) $row->eligible_view_count || $hasSelfAttribution) {
                    return ApiResponse::error(
                        'UNRESOLVED_ATTRIBUTION',
                        'Missing creator ownership or a creator self-view prevents this impression from being cleared.',
                        409,
                    );
                }
            }

            $allocations = DB::table('creator_ad_revenue_allocations')
                ->where('ad_impression_id', $impression)
                ->lockForUpdate()
                ->get();
            if ($data['decision'] === 'reject' && $allocations->contains(fn (object $allocation): bool => $allocation->status === 'released')) {
                return ApiResponse::error(
                    'CLAWBACK_REQUIRED',
                    'This impression has released funds. Apply an audited reserve deduction or recovery workflow instead.',
                    409,
                );
            }

            if ($data['decision'] === 'reject') {
                foreach ($allocations->where('status', 'pending') as $allocation) {
                    $reversal = $reverse->handle(
                        $allocation->pending_ledger_transaction_id,
                        "reel-ad-allocation:{$allocation->id}:fraud-reversal",
                        $data['reason'],
                    );
                    DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
                        'status' => 'reversed',
                        'reversal_ledger_transaction_id' => $reversal->id,
                        'review_reason_code' => $data['reason_code'],
                        'review_reason' => $data['reason'],
                        'reviewed_by' => auth('admin')->id(),
                        'reviewed_at' => now(),
                        'reversed_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('ad_impressions')->where('id', $impression)->update([
                'fraud_state' => $data['decision'] === 'clear' ? 'clear' : 'rejected',
                'updated_at' => now(),
            ]);
            $this->audit($request, 'reels_ad_impression.reviewed', $impression, $data['reason'], [
                'decision' => $data['decision'],
                'reason_code' => $data['reason_code'],
                'allocation_count' => $allocations->count(),
            ]);

            if ($data['decision'] === 'clear' && $row->status === 'watched' && $allocations->isEmpty()) {
                DB::afterCommit(fn () => AllocateReelAdRevenue::dispatch($impression));
            }

            return ApiResponse::success([
                'ad_impression_id' => $impression,
                'fraud_state' => $data['decision'] === 'clear' ? 'clear' : 'rejected',
            ]);
        }, 3);
    }

    public function reserveTrueUp(
        Request $request,
        PostLedgerTransaction $post,
    ): JsonResponse {
        $data = $request->validate([
            'creator_profile_id' => ['required', 'string', 'exists:creator_profiles,id'],
            'action' => ['required', 'in:release,deduct'],
            'usd_micros' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'reason_code' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            return DB::transaction(function () use ($request, $post, $data): JsonResponse {
                $reserve = $this->account($data['creator_profile_id'], 'reels_ad_reserve', 'USD');
                $reserveBalance = (int) DB::table('financial_accounts')->where('id', $reserve->id)->lockForUpdate()->value('balance');
                $reserveUsed = min($reserveBalance, $data['usd_micros']);
                $futureOffset = 0;
                if ($data['action'] === 'release') {
                    if ($reserveUsed < $data['usd_micros']) {
                        return ApiResponse::error('INSUFFICIENT_RESERVE', 'The release exceeds the creator USD reserve.', 409);
                    }
                    $destination = $this->account($data['creator_profile_id'], 'reels_ad_available', 'USD');
                    $entries = [
                        ['account_id' => $reserve->id, 'amount' => -$data['usd_micros']],
                        ['account_id' => $destination->id, 'amount' => $data['usd_micros']],
                    ];
                } else {
                    $funding = $this->systemAccount('reels_ad_revenue_funding', 'USD');
                    $offset = $this->account($data['creator_profile_id'], 'reels_ad_clawback_offset', 'USD');
                    $futureOffset = $data['usd_micros'] - $reserveUsed;
                    $entries = array_values(array_filter([
                        ['account_id' => $reserve->id, 'amount' => -$reserveUsed],
                        ['account_id' => $offset->id, 'amount' => -$futureOffset],
                        ['account_id' => $funding->id, 'amount' => $data['usd_micros']],
                    ], fn (array $entry): bool => $entry['amount'] !== 0));
                }
                $transaction = $post->handle(
                    $data['action'] === 'release' ? 'reel.ad.reserve_released' : 'reel.ad.reserve_deducted',
                    'reel-ad-reserve:'.$data['idempotency_key'],
                    'USD',
                    $entries,
                    [
                        'creator_profile_id' => $data['creator_profile_id'],
                        'reason_code' => $data['reason_code'],
                        'admin_id' => auth('admin')->id(),
                        'reserve_used_usd_micros' => $reserveUsed,
                        'future_offset_usd_micros' => $futureOffset,
                    ],
                );
                $this->audit($request, 'reels_ad_reserve.'.$data['action'], $transaction->id, $data['reason'], [
                    'creator_profile_id' => $data['creator_profile_id'],
                    'usd_micros' => $data['usd_micros'],
                    'reserve_used_usd_micros' => $reserveUsed,
                    'future_offset_usd_micros' => $futureOffset,
                    'reason_code' => $data['reason_code'],
                ]);

                return ApiResponse::success([
                    'ledger_transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'reserve_used_usd_micros' => $reserveUsed,
                    'future_offset_usd_micros' => $futureOffset,
                ]);
            }, 3);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage() === 'INSUFFICIENT_COINS' ? 'INSUFFICIENT_RESERVE' : 'INVALID_ADJUSTMENT';

            return ApiResponse::error($code, $exception->getMessage(), 409);
        }
    }

    public function settleFxClearing(
        Request $request,
        PostLedgerTransaction $post,
    ): JsonResponse {
        $data = $request->validate([
            'unit' => ['required', 'in:USD,NGN'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'provider_reference' => ['required', 'string', 'max:191'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $post, $data): JsonResponse {
            $clearing = $this->systemAccount('reels_ad_fx_clearing', $data['unit']);
            $settlement = $this->systemAccount('reels_ad_fx_settlement', $data['unit']);
            $balance = (int) DB::table('financial_accounts')->where('id', $clearing->id)->lockForUpdate()->value('balance');
            $direction = $data['unit'] === 'USD' ? -1 : 1;
            if (
                ($data['unit'] === 'USD' && $balance < $data['amount_minor'])
                || ($data['unit'] === 'NGN' && abs(min(0, $balance)) < $data['amount_minor'])
            ) {
                return ApiResponse::error('CLEARING_AMOUNT_EXCEEDED', 'The settlement exceeds the open clearing balance.', 409);
            }

            $transaction = $post->handle(
                'reel.ad.fx_clearing_settled',
                'reel-ad-fx-settlement:'.$data['idempotency_key'],
                $data['unit'],
                [
                    ['account_id' => $clearing->id, 'amount' => $direction * $data['amount_minor']],
                    ['account_id' => $settlement->id, 'amount' => -$direction * $data['amount_minor']],
                ],
                [
                    'provider_reference' => $data['provider_reference'],
                    'admin_id' => auth('admin')->id(),
                ],
            );
            $this->audit($request, 'reels_ad_fx_clearing.settled', $transaction->id, $data['reason'], [
                'unit' => $data['unit'],
                'amount_minor' => $data['amount_minor'],
                'provider_reference' => $data['provider_reference'],
            ]);

            return ApiResponse::success([
                'ledger_transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
            ]);
        }, 3);
    }

    private function account(string $creatorId, string $type, string $unit): FinancialAccount
    {
        return FinancialAccount::firstOrCreate(
            ['owner_type' => 'App\\Models\\CreatorProfile', 'owner_id' => $creatorId, 'type' => $type, 'unit' => $unit],
            ['balance' => 0],
        );
    }

    private function systemAccount(string $type, string $unit): FinancialAccount
    {
        return FinancialAccount::firstOrCreate(
            ['owner_type' => null, 'owner_id' => null, 'type' => $type, 'unit' => $unit],
            ['balance' => 0],
        );
    }

    private function audit(Request $request, string $action, string $id, string $reason, array $after): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'admin_id' => auth('admin')->id(),
            'action' => $action,
            'subject_type' => 'App\\Models\\AdmobReconciliationBatch',
            'subject_id' => $id,
            'reason' => $reason,
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
