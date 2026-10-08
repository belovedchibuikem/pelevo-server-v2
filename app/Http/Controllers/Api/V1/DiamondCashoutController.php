<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\FinancialAccount;
use App\Services\Finance\CoinEconomy;
use App\Support\AgeMajority;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DiamondCashoutController extends Controller
{
    public function show(Request $request, CoinEconomy $economy): JsonResponse
    {
        $creator = $this->creator($request);
        if (! $creator) {
            return ApiResponse::error('NOT_FOUND', 'A creator profile is required before diamonds can be shown.', 404);
        }

        return ApiResponse::success($economy->summary($economy->balanceForCreators([$creator->id])));
    }

    public function index(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        if (! $creator) {
            return ApiResponse::success([]);
        }
        $rows = DB::table('diamond_cashouts')->where('creator_profile_id', $creator->id)->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();

        return ApiResponse::success($rows->map(fn (object $row): array => $this->present($row))->values());
    }

    public function store(Request $request, CoinEconomy $economy, PostLedgerTransaction $post): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Diamond cashouts are awaiting finance sign-off.', 503);
        }
        if ($denied = AgeMajority::missing($request->user())) {
            return $denied;
        }
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '') {
            return ApiResponse::error('VALIDATION', 'Idempotency-Key header is required.', 422, ['Idempotency-Key' => ['Required']]);
        }
        $creator = $this->creator($request);
        if (! $creator) {
            return ApiResponse::error('NOT_FOUND', 'A creator profile is required before diamonds can be cashed out.', 404);
        }
        $regime = $economy->active();
        if (! $regime) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Diamond conversion is not configured.', 503);
        }
        $data = $request->validate([
            'payout_method_id' => ['required', 'string'],
            'diamonds' => ['required', 'integer', 'min:'.$regime->min_cashout_diamonds],
            'currency' => ['required', 'in:NGN,USD,ngn,usd'],
        ]);
        $currency = strtoupper($data['currency']);
        $method = DB::table('payout_methods')->where('id', $data['payout_method_id'])->where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->first();
        if (! $method || ! $method->verified_at) {
            return ApiResponse::error('PAYOUT_METHOD_UNVERIFIED', 'A verified payout method is required.', 409);
        }
        if ($currency === 'NGN' && ! ($method->kind === 'bank' && in_array($method->provider, ['paystack', 'flutterwave'], true))) {
            return ApiResponse::error('VALIDATION', 'Naira diamond cashouts are paid to a verified bank account.', 422);
        }
        if ($currency === 'USD' && $method->provider !== 'paypal') {
            return ApiResponse::error('VALIDATION', 'Dollar diamond cashouts are paid to a verified PayPal account.', 422);
        }
        $quote = $economy->cashoutQuote((int) $data['diamonds'], $currency, $regime);
        if ($quote['net_minor'] <= 0) {
            return ApiResponse::error('VALIDATION', 'The cashout is smaller than the transfer fee.', 422);
        }
        $requestHash = hash('sha256', json_encode([$request->user()->id, $creator->id, $method->id, (int) $data['diamonds'], $currency], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($request, $post, $key, $data, $method, $creator, $regime, $quote, $currency, $requestHash): JsonResponse {
                $existing = DB::table('diamond_cashouts')->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
                    }

                    return ApiResponse::success($this->present($existing));
                }
                $wallet = FinancialAccount::firstOrCreate(['owner_type' => CreatorProfile::class, 'owner_id' => $creator->id, 'type' => 'diamond_wallet', 'unit' => CoinEconomy::UNIT], ['balance' => 0]);
                $redemption = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'diamond_redemption', 'unit' => CoinEconomy::UNIT], ['balance' => 0]);
                $transaction = $post->handle('diamond.cashout_reserved', $key, CoinEconomy::UNIT, [
                    ['account_id' => $wallet->id, 'amount' => -((int) $data['diamonds'])],
                    ['account_id' => $redemption->id, 'amount' => (int) $data['diamonds']],
                ], [
                    'creator_profile_id' => $creator->id,
                    'currency' => $currency,
                    'gross_minor' => $quote['gross_minor'],
                    'transfer_fee_minor' => $quote['transfer_fee_minor'],
                    'net_minor' => $quote['net_minor'],
                    'economy_regime_id' => $regime->id,
                    'request_hash' => $requestHash,
                ]);
                $id = (string) Str::ulid();
                DB::table('diamond_cashouts')->insert([
                    'id' => $id,
                    'user_id' => $request->user()->id,
                    'creator_profile_id' => $creator->id,
                    'payout_method_id' => $method->id,
                    'ledger_transaction_id' => $transaction->id,
                    'economy_regime_id' => $regime->id,
                    'diamonds' => (int) $data['diamonds'],
                    'currency' => $currency,
                    'gross_minor' => $quote['gross_minor'],
                    'transfer_fee_minor' => $quote['transfer_fee_minor'],
                    'net_minor' => $quote['net_minor'],
                    'store_fee_basis_points' => (int) $regime->store_fee_basis_points,
                    'creator_split_basis_points' => (int) $regime->creator_split_basis_points,
                    'diamond_rate' => $currency === 'NGN' ? (int) $regime->diamond_ngn_rate : (int) $regime->diamond_usd_rate,
                    'state' => 'queued',
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ApiResponse::success($this->present(DB::table('diamond_cashouts')->find($id)), status: 201);
            }, 3);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'INSUFFICIENT_COINS') {
                return ApiResponse::error('INSUFFICIENT_DIAMONDS', 'Diamond balance is insufficient.', 409);
            }
            if ($exception->getMessage() === 'IDEMPOTENCY_CONFLICT') {
                return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
            }
            throw $exception;
        }
    }

    private function creator(Request $request): ?CreatorProfile
    {
        return CreatorProfile::query()->where('user_id', $request->user()->id)->first();
    }

    private function present(object $row): array
    {
        return [
            'id' => $row->id,
            'diamonds' => (int) $row->diamonds,
            'currency' => $row->currency,
            'gross_minor' => (int) $row->gross_minor,
            'transfer_fee_minor' => (int) $row->transfer_fee_minor,
            'net_minor' => (int) $row->net_minor,
            'state' => $row->state,
            'rate_includes_app_share' => true,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
