<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Models\ConfigurationVersion;
use App\Models\FinancialAccount;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WithdrawalController extends Controller
{
    public function store(Request $request, PostLedgerTransaction $post): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Withdrawals are awaiting finance sign-off.', 503);
        }
        $key = (string) $request->header('Idempotency-Key');
        if (! $key) {
            return ApiResponse::error('VALIDATION', 'Idempotency-Key header is required.', 422);
        }
        $config = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first();
        $minimum = (int) data_get($config?->payload, 'money.earn_min_withdraw_coins', config('finance.earn_min_withdraw_coins'));
        $data = $request->validate(['payout_method_id' => ['required', 'string'], 'coins' => ['required', 'integer', 'min:'.$minimum]]);
        $method = DB::table('payout_methods')->where('id', $data['payout_method_id'])->where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->first();
        if (! $method || ! $method->verified_at) {
            return ApiResponse::error('PAYOUT_METHOD_UNVERIFIED', 'A verified payout method is required.', 409);
        }
        $requestHash = hash('sha256', json_encode([$request->user()->id, $method->id, (int) $data['coins']], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($request, $post, $key, $data, $method, $requestHash): JsonResponse {
                $existing = DB::table('withdrawals')->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if (! $existing->request_hash || ! hash_equals($existing->request_hash, $requestHash)) {
                        return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
                    }

                    return ApiResponse::success($this->present($existing));
                }
                $wallet = FinancialAccount::firstOrCreate(['owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'type' => 'earn_wallet', 'unit' => 'ECN'], ['balance' => 0]);
                $payable = FinancialAccount::firstOrCreate(['owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'type' => 'withdrawal_payable', 'unit' => 'ECN'], ['balance' => 0]);
                $tx = $post->handle('withdrawal.reserved', $key, 'ECN', [['account_id' => $wallet->id, 'amount' => -$data['coins']], ['account_id' => $payable->id, 'amount' => $data['coins']]], ['withdrawal_request_hash' => $requestHash]);
                $id = (string) Str::ulid();
                DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $request->user()->id, 'payout_method_id' => $method->id, 'ledger_transaction_id' => $tx->id, 'coins' => $data['coins'], 'state' => 'queued', 'idempotency_key' => $key, 'request_hash' => $requestHash, 'created_at' => now(), 'updated_at' => now()]);

                return ApiResponse::success($this->present(DB::table('withdrawals')->find($id)), status: 201);
            }, 3);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_COINS') {
                return ApiResponse::error('INSUFFICIENT_COINS', 'Earn wallet balance is insufficient.', 409);
            }
            if ($e->getMessage() === 'IDEMPOTENCY_CONFLICT') {
                return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
            }
            throw $e;
        }
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,50'], 'cursor' => ['sometimes', 'string', 'max:2048']]);
        $page = DB::table('withdrawals')->where('user_id', $request->user()->id)
            ->select('id', 'coins', 'state', 'created_at')->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($data['limit'] ?? 20);
        $items = collect($page->items())->map(fn (object $row): array => [
            'id' => $row->id, 'coins' => (string) $row->coins, 'state' => $row->state,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ]);

        return ApiResponse::success($items, ['user_id' => $request->user()->id, 'cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }

    private function present(?object $row): array
    {
        return [
            'id' => $row?->id, 'coins' => (string) $row?->coins, 'state' => $row?->state,
            'created_at' => $row?->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
        ];
    }
}
