<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\FinancialAccount;
use App\Services\Finance\CoinEconomy;
use App\Services\Finance\DisplayFx;
use App\Services\Finance\PayoutRoute;
use App\Support\AgeMajority;
use App\Support\ApiResponse;
use App\Support\CountryCurrency;
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

        return ApiResponse::success($economy->summary($economy->balanceForCreators([$creator->id]), $request->user()->country_code));
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
            'currency' => ['required', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $method = DB::table('payout_methods')->where('id', $data['payout_method_id'])->where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->first();
        if (! $method || ! $method->verified_at) {
            return ApiResponse::error('PAYOUT_METHOD_UNVERIFIED', 'A verified payout method is required.', 409);
        }
        try {
            $route = app(PayoutRoute::class)->resolve($request->user()->country_code, $method);
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage() === 'AFRICAN_BANK_REQUIRED'
                ? 'African diamond cashouts are paid to a verified Paystack or Flutterwave bank account.'
                : 'International diamond cashouts are paid to a verified PayPal account.';

            return ApiResponse::error('VALIDATION', $message, 422);
        }
        $currency = $route['currency'];
        if (strtoupper((string) $data['currency']) !== $currency) {
            return ApiResponse::error('VALIDATION', 'Cash out in '.$currency.' for this creator.', 422);
        }
        $quote = $economy->cashoutQuote((int) $data['diamonds'], $regime);
        if (! $quote['payable']) {
            return ApiResponse::error('VALIDATION', 'Diamond cashouts start at $15.00.', 422);
        }
        $fx = null;
        $grossMinor = (int) $quote['gross_minor'];
        $netMinor = (int) $quote['net_minor'];
        $feeMinor = (int) $quote['transfer_fee_minor'];
        if ($currency !== 'USD') {
            $fx = app(DisplayFx::class)->quote($currency);
            if (! $fx) {
                return ApiResponse::error('SERVICE_DEGRADED', 'A live exchange rate is not available for this payout.', 503);
            }
            $digits = CountryCurrency::fractionDigits($currency);
            $fxDisplay = app(DisplayFx::class);
            $grossMinor = $fxDisplay->convertUsdCents((int) $quote['gross_minor'], (string) $fx->rate, $digits);
            $netMinor = $fxDisplay->convertUsdCents((int) $quote['net_minor'], (string) $fx->rate, $digits);
            $feeMinor = max(0, $grossMinor - $netMinor);
        }
        if ($netMinor <= 0) {
            return ApiResponse::error('VALIDATION', 'The cashout is smaller than the transfer fee.', 422);
        }
        $requestHash = hash('sha256', json_encode([$request->user()->id, $creator->id, $method->id, (int) $data['diamonds'], $currency], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($request, $post, $key, $data, $method, $creator, $regime, $quote, $currency, $requestHash, $grossMinor, $netMinor, $feeMinor, $fx): JsonResponse {
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
                    'currency' => 'USD',
                    'gross_usd_cents' => $quote['gross_minor'],
                    'payout_currency' => $currency,
                    'payout_gross_minor' => $grossMinor,
                    'payout_net_minor' => $netMinor,
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
                    'gross_usd_cents' => (int) $quote['gross_minor'],
                    'currency' => $currency,
                    'gross_minor' => $grossMinor,
                    'transfer_fee_minor' => $feeMinor,
                    'net_minor' => $netMinor,
                    'store_fee_basis_points' => (int) $regime->store_fee_basis_points,
                    'creator_split_basis_points' => (int) $regime->creator_split_basis_points,
                    'diamond_rate' => (int) $regime->diamond_usd_rate,
                    'fx_rate_version_id' => $fx?->id,
                    'fx_rate' => $fx?->rate,
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
            'gross_usd_cents' => $row->gross_usd_cents === null ? null : (int) $row->gross_usd_cents,
            'currency' => $row->currency,
            'gross_minor' => (int) $row->gross_minor,
            'transfer_fee_minor' => (int) $row->transfer_fee_minor,
            'net_minor' => (int) $row->net_minor,
            'fx_rate' => $row->fx_rate === null ? null : app(DisplayFx::class)->formatRate((string) $row->fx_rate),
            'state' => $row->state,
            'rate_includes_app_share' => true,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
