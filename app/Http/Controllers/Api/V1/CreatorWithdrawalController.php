<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\FinancialAccount;
use App\Services\Finance\AdRevenueClearance;
use App\Services\Finance\CoinEconomy;
use App\Services\Finance\DisplayFx;
use App\Services\Finance\PayoutRoute;
use App\Services\Finance\TokenValuation;
use App\Support\AgeMajority;
use App\Support\ApiResponse;
use App\Support\CountryCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creator-wide withdrawal requests for ad revenue and gift diamonds.
 * Funds are reserved for admin review; payout is never automatic.
 */
final class CreatorWithdrawalController extends Controller
{
    public function workspace(Request $request, CoinEconomy $economy, AdRevenueClearance $ads): JsonResponse
    {
        $creator = $this->creator($request);
        if (! $creator) {
            return ApiResponse::error('NOT_FOUND', 'A creator profile is required before withdrawals can be shown.', 404);
        }
        $country = $request->user()->country_code;
        $diamonds = $economy->summary($economy->balanceForCreators([$creator->id]), $country);
        $adClearance = $ads->present([$creator->id], $country);
        $availableMicros = (int) DB::table('financial_accounts')
            ->where('owner_type', CreatorProfile::class)
            ->where('owner_id', $creator->id)
            ->where('type', 'reels_ad_available')
            ->where('unit', 'USD')
            ->value('balance');
        $availableCents = intdiv(max(0, $availableMicros), 10_000);
        $methods = DB::table('payout_methods')
            ->where('owner_type', get_class($request->user()))
            ->where('owner_id', $request->user()->id)
            ->select(['id', 'provider', 'kind', 'label', 'destination_last_four', 'verified_at', 'proof_status', 'created_at'])
            ->latest()
            ->get();
        $verified = $methods->first(fn (object $row): bool => $row->verified_at !== null);
        $payoutCurrency = null;
        if ($verified) {
            try {
                $payoutCurrency = app(PayoutRoute::class)->resolve($country, $verified)['currency'];
            } catch (InvalidArgumentException) {
                $payoutCurrency = null;
            }
        }

        return ApiResponse::success([
            'gifts' => [
                ...$diamonds,
                'threshold_usd' => TokenValuation::formatCents(TokenValuation::GIFT_MIN_USD_CENTS),
                'threshold_usd_cents' => TokenValuation::GIFT_MIN_USD_CENTS,
                'eligible' => (bool) (($diamonds['quotes']['USD']['payable'] ?? false) === true),
            ],
            'ads' => [
                ...$adClearance,
                'available_usd_micros' => $availableMicros,
                'available_usd_cents' => $availableCents,
                'available_usd' => TokenValuation::formatCents($availableCents),
                'threshold_usd' => TokenValuation::formatCents(TokenValuation::AD_MIN_USD_CENTS),
                'threshold_usd_cents' => TokenValuation::AD_MIN_USD_CENTS,
                'eligible' => $availableCents >= TokenValuation::AD_MIN_USD_CENTS,
                'scope' => 'all_claimed_podcasts',
            ],
            'payout_methods' => $methods,
            'has_verified_payout_method' => $verified !== null,
            'payout_currency' => $payoutCurrency,
            'manual_payout' => true,
            'note' => 'Withdrawals are requested by you and paid by Pelevo finance. Nothing is sent automatically.',
        ]);
    }

    public function store(Request $request, CoinEconomy $economy, PostLedgerTransaction $post): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Creator withdrawals are awaiting finance sign-off.', 503);
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
            return ApiResponse::error('NOT_FOUND', 'A creator profile is required before withdrawals can be requested.', 404);
        }
        $data = $request->validate([
            'source' => ['required', 'in:gifts,ads'],
            'payout_method_id' => ['required', 'string'],
            'diamonds' => ['required_if:source,gifts', 'integer', 'min:1'],
            'amount_usd_cents' => ['required_if:source,ads', 'integer', 'min:'.TokenValuation::AD_MIN_USD_CENTS],
            'currency' => ['required', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $method = DB::table('payout_methods')
            ->where('id', $data['payout_method_id'])
            ->where('owner_type', get_class($request->user()))
            ->where('owner_id', $request->user()->id)
            ->first();
        if (! $method || ! $method->verified_at) {
            return ApiResponse::error('PAYOUT_METHOD_REQUIRED', 'Set up and verify a payout method before requesting a withdrawal.', 409);
        }
        try {
            $route = app(PayoutRoute::class)->resolve($request->user()->country_code, $method);
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage() === 'AFRICAN_BANK_REQUIRED'
                ? 'African creator withdrawals are paid to a verified Flutterwave or Paystack bank account.'
                : 'International creator withdrawals are paid to a verified PayPal account.';

            return ApiResponse::error('VALIDATION', $message, 422);
        }
        $currency = $route['currency'];
        if (strtoupper((string) $data['currency']) !== $currency) {
            return ApiResponse::error('VALIDATION', 'Request this withdrawal in '.$currency.'.', 422);
        }

        return $data['source'] === 'gifts'
            ? $this->storeGiftCashout($request, $economy, $post, $creator, $method, $data, $currency, $key)
            : $this->storeAdCashout($request, $post, $creator, $method, $data, $currency, $key);
    }

    public function index(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        if (! $creator) {
            return ApiResponse::success([]);
        }
        $gifts = DB::table('diamond_cashouts')
            ->where('creator_profile_id', $creator->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'source' => 'gifts',
                'amount_label' => ((int) $row->diamonds).' diamonds',
                'net_minor' => (int) $row->net_minor,
                'currency' => $row->currency,
                'state' => $row->state,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ]);
        $ads = DB::table('creator_ad_cashouts')
            ->where('creator_profile_id', $creator->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'source' => 'ads',
                'amount_label' => TokenValuation::formatCents((int) $row->gross_usd_cents).' ads',
                'net_minor' => (int) $row->net_minor,
                'currency' => $row->currency,
                'state' => $row->state,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ]);

        return ApiResponse::success(
            $gifts->concat($ads)->sortByDesc('created_at')->values()->take(50)->values()->all(),
        );
    }

    private function storeGiftCashout(
        Request $request,
        CoinEconomy $economy,
        PostLedgerTransaction $post,
        CreatorProfile $creator,
        object $method,
        array $data,
        string $currency,
        string $key,
    ): JsonResponse {
        $regime = $economy->active();
        if (! $regime) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Diamond conversion is not configured.', 503);
        }
        if ((int) $data['diamonds'] < (int) $regime->min_cashout_diamonds) {
            return ApiResponse::error('VALIDATION', 'Gift withdrawals start at $15.00.', 422);
        }
        $quote = $economy->cashoutQuote((int) $data['diamonds'], $regime);
        if (! $quote['payable']) {
            return ApiResponse::error('VALIDATION', 'Gift withdrawals start at $15.00.', 422);
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
        $requestHash = hash('sha256', json_encode([$request->user()->id, $creator->id, $method->id, 'gifts', (int) $data['diamonds'], $currency], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($request, $post, $key, $data, $method, $creator, $regime, $quote, $currency, $requestHash, $grossMinor, $netMinor, $feeMinor, $fx): JsonResponse {
                $existing = DB::table('diamond_cashouts')->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
                    }

                    return ApiResponse::success($this->presentGift($existing), status: 200);
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
                    'manual_payout' => true,
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

                return ApiResponse::success($this->presentGift(DB::table('diamond_cashouts')->find($id)), status: 201);
            }, 3);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'INSUFFICIENT_COINS') {
                return ApiResponse::error('INSUFFICIENT_DIAMONDS', 'Gift coin balance is insufficient.', 409);
            }
            if ($exception->getMessage() === 'IDEMPOTENCY_CONFLICT') {
                return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
            }
            throw $exception;
        }
    }

    private function storeAdCashout(
        Request $request,
        PostLedgerTransaction $post,
        CreatorProfile $creator,
        object $method,
        array $data,
        string $currency,
        string $key,
    ): JsonResponse {
        $usdCents = (int) $data['amount_usd_cents'];
        if ($usdCents < TokenValuation::AD_MIN_USD_CENTS) {
            return ApiResponse::error('VALIDATION', 'Ad revenue withdrawals start at $100.00.', 422);
        }
        $amountMicros = $usdCents * 10_000;
        $fx = null;
        $grossMinor = $usdCents;
        $netMinor = $usdCents;
        if ($currency !== 'USD') {
            $fx = app(DisplayFx::class)->quote($currency);
            if (! $fx) {
                return ApiResponse::error('SERVICE_DEGRADED', 'A live exchange rate is not available for this payout.', 503);
            }
            $digits = CountryCurrency::fractionDigits($currency);
            $grossMinor = app(DisplayFx::class)->convertUsdCents($usdCents, (string) $fx->rate, $digits);
            $netMinor = $grossMinor;
        }
        $requestHash = hash('sha256', json_encode([$request->user()->id, $creator->id, $method->id, 'ads', $usdCents, $currency], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($request, $post, $key, $method, $creator, $currency, $requestHash, $amountMicros, $usdCents, $grossMinor, $netMinor, $fx): JsonResponse {
                $existing = DB::table('creator_ad_cashouts')->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
                    }

                    return ApiResponse::success($this->presentAd($existing), status: 200);
                }
                $source = FinancialAccount::firstOrCreate(
                    ['owner_type' => CreatorProfile::class, 'owner_id' => $creator->id, 'type' => 'reels_ad_available', 'unit' => 'USD'],
                    ['balance' => 0],
                );
                $payable = FinancialAccount::firstOrCreate(
                    ['owner_type' => CreatorProfile::class, 'owner_id' => $creator->id, 'type' => 'creator_payout_payable', 'unit' => 'USD'],
                    ['balance' => 0],
                );
                $transaction = $post->handle('creator_ad.cashout_reserved', $key, 'USD', [
                    ['account_id' => $source->id, 'amount' => -$amountMicros],
                    ['account_id' => $payable->id, 'amount' => $amountMicros],
                ], [
                    'creator_profile_id' => $creator->id,
                    'gross_usd_cents' => $usdCents,
                    'payout_currency' => $currency,
                    'payout_net_minor' => $netMinor,
                    'request_hash' => $requestHash,
                    'manual_payout' => true,
                    'scope' => 'all_claimed_podcasts',
                ]);
                $id = (string) Str::ulid();
                DB::table('creator_ad_cashouts')->insert([
                    'id' => $id,
                    'user_id' => $request->user()->id,
                    'creator_profile_id' => $creator->id,
                    'payout_method_id' => $method->id,
                    'ledger_transaction_id' => $transaction->id,
                    'fx_rate_version_id' => $fx?->id,
                    'amount_usd_micros' => $amountMicros,
                    'gross_usd_cents' => $usdCents,
                    'currency' => $currency,
                    'gross_minor' => $grossMinor,
                    'net_minor' => $netMinor,
                    'fx_rate' => $fx?->rate,
                    'state' => 'queued',
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ApiResponse::success($this->presentAd(DB::table('creator_ad_cashouts')->find($id)), status: 201);
            }, 3);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'INSUFFICIENT_COINS') {
                return ApiResponse::error('INSUFFICIENT_ADS', 'Available ad revenue is insufficient.', 409);
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

    private function presentGift(object $row): array
    {
        return [
            'id' => $row->id,
            'source' => 'gifts',
            'diamonds' => (int) $row->diamonds,
            'gross_usd_cents' => $row->gross_usd_cents === null ? null : (int) $row->gross_usd_cents,
            'currency' => $row->currency,
            'gross_minor' => (int) $row->gross_minor,
            'net_minor' => (int) $row->net_minor,
            'state' => $row->state,
            'manual_payout' => true,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    private function presentAd(object $row): array
    {
        return [
            'id' => $row->id,
            'source' => 'ads',
            'amount_usd_micros' => (int) $row->amount_usd_micros,
            'gross_usd_cents' => (int) $row->gross_usd_cents,
            'currency' => $row->currency,
            'gross_minor' => (int) $row->gross_minor,
            'net_minor' => (int) $row->net_minor,
            'state' => $row->state,
            'manual_payout' => true,
            'scope' => 'all_claimed_podcasts',
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
