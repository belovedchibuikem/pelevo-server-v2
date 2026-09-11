<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchCreatorPayout;
use App\Models\FinancialAccount;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreatorPayoutController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '') {
            return ApiResponse::error('VALIDATION', 'Idempotency-Key header is required.', 422);
        }
        $data = $request->validate(['period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'], 'unit' => ['required', 'in:PCN'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($request, $data, $key): JsonResponse {
            $existing = DB::table('creator_payout_batches')->where('idempotency_key', $key)->first();
            if ($existing) {
                return ApiResponse::success($existing);
            }
            $batchId = (string) Str::ulid();
            DB::table('creator_payout_batches')->insert(['id' => $batchId, ...$data, 'state' => 'draft', 'idempotency_key' => $key, 'prepared_by' => auth('admin')->id(), 'created_at' => now(), 'updated_at' => now()]);
            $count = 0;
            $total = 0;
            $settings = DB::table('creator_payout_settings')->where('compliance_state', 'verified')->whereNotNull('payout_method_id')->orderBy('creator_profile_id')->get();
            foreach ($settings as $setting) {
                $account = FinancialAccount::where('owner_type', 'App\\Models\\CreatorProfile')->where('owner_id', $setting->creator_profile_id)->where('type', 'creator_balance')->where('unit', $data['unit'])->first();
                if (! $account || $account->balance < $setting->minimum_amount) {
                    continue;
                }
                $fx = DB::table('fx_rate_versions')->where('base_unit', $data['unit'])->where('quote_currency', $setting->currency)->where('effective_at', '<=', $data['period_end'].' 23:59:59')->latest('effective_at')->first();
                if (! $fx) {
                    continue;
                }
                $amount = (int) $account->balance;
                DB::table('creator_payouts')->insert(['id' => (string) Str::ulid(), 'creator_payout_batch_id' => $batchId, 'creator_profile_id' => $setting->creator_profile_id, 'payout_method_id' => $setting->payout_method_id, 'fx_rate_version_id' => $fx->id, 'unit' => $data['unit'], 'amount' => $amount, 'currency' => $setting->currency, 'amount_minor' => (int) round($amount * (float) $fx->rate), 'state' => 'queued', 'idempotency_key' => 'creator-payout:'.$batchId.':'.$setting->creator_profile_id, 'created_at' => now(), 'updated_at' => now()]);
                $count++;
                $total += $amount;
            }
            DB::table('creator_payout_batches')->where('id', $batchId)->update(['payout_count' => $count, 'total_amount' => $total, 'updated_at' => now()]);
            $this->audit($request, 'creator_payout_batch.prepared', $batchId, $data['reason'], ['count' => $count, 'total' => $total]);

            return ApiResponse::success(DB::table('creator_payout_batches')->find($batchId), status: 201);
        }, 3);
    }

    public function show(string $batch): JsonResponse
    {
        $row = DB::table('creator_payout_batches')->where('id', $batch)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Creator payout batch not found.', 404);
        }

        return ApiResponse::success(['batch' => $row, 'payouts' => DB::table('creator_payouts')->where('creator_payout_batch_id', $batch)->orderBy('creator_profile_id')->get()]);
    }

    public function approve(string $batch, Request $request, PostLedgerTransaction $post): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($batch, $request, $post, $data): JsonResponse {
            $row = DB::table('creator_payout_batches')->where('id', $batch)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Creator payout batch not found.', 404);
            }
            if ($row->prepared_by === auth('admin')->id()) {
                return ApiResponse::error('MAKER_CHECKER_REQUIRED', 'A different finance administrator must approve this batch.', 409);
            }
            if ($row->state !== 'draft') {
                return ApiResponse::error('CONFLICT', 'Only draft payout batches can be approved.', 409);
            }
            foreach (DB::table('creator_payouts')->where('creator_payout_batch_id', $batch)->orderBy('creator_profile_id')->lockForUpdate()->get() as $payout) {
                $source = FinancialAccount::where('owner_type', 'App\\Models\\CreatorProfile')->where('owner_id', $payout->creator_profile_id)->where('type', 'creator_balance')->where('unit', $payout->unit)->firstOrFail();
                $payable = FinancialAccount::firstOrCreate(['owner_type' => 'App\\Models\\CreatorProfile', 'owner_id' => $payout->creator_profile_id, 'type' => 'creator_payout_payable', 'unit' => $payout->unit], ['balance' => 0]);
                $transaction = $post->handle('creator_payout.reserved', $payout->idempotency_key, $payout->unit, [['account_id' => $source->id, 'amount' => -$payout->amount], ['account_id' => $payable->id, 'amount' => $payout->amount]], ['batch_id' => $batch, 'fx_rate_version_id' => $payout->fx_rate_version_id]);
                DB::table('creator_payouts')->where('id', $payout->id)->update(['ledger_transaction_id' => $transaction->id, 'state' => 'approved', 'updated_at' => now()]);
                DispatchCreatorPayout::dispatch($payout->id)->afterCommit();
            }
            DB::table('creator_payout_batches')->where('id', $batch)->update(['state' => 'approved', 'approved_by' => auth('admin')->id(), 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'creator_payout_batch.approved', $batch, $data['reason'], ['checker' => auth('admin')->id()]);

            return ApiResponse::success(DB::table('creator_payout_batches')->find($batch));
        }, 3);
    }

    private function audit(Request $request, string $action, string $id, string $reason, array $after): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => auth('admin')->id(), 'action' => $action, 'subject_type' => 'App\\Models\\CreatorPayoutBatch', 'subject_id' => $id, 'reason' => $reason, 'after' => json_encode($after), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
