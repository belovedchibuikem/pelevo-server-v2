<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\FinancialAccount;
use App\Models\GiftType;
use App\Models\User;
use App\Services\Finance\CoinEconomy;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class GiftController extends Controller
{
    public function catalog(): JsonResponse
    {
        return ApiResponse::success(GiftType::where('active', true)->orderBy('coins')->get(['id', 'slug', 'name', 'coins', 'version']));
    }

    public function wallet(Request $request): JsonResponse
    {
        return ApiResponse::success(['unit' => 'PCN', 'balance' => $this->walletBalance($request->user())]);
    }

    public function activity(Request $request): JsonResponse
    {
        $account = FinancialAccount::where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->where('type', 'gift_wallet')->where('unit', 'PCN')->first();
        if (! $account) {
            return ApiResponse::success([]);
        }
        $rows = DB::table('ledger_entries')->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')->where('ledger_entries.financial_account_id', $account->id)->orderByDesc('ledger_entries.created_at')->orderByDesc('ledger_entries.id')->limit(min($request->integer('limit', 50), 100))->get(['ledger_entries.id', 'ledger_transactions.event_type', 'ledger_entries.amount', 'ledger_entries.unit', 'ledger_entries.created_at']);

        return ApiResponse::success($rows->map(fn (object $row): array => [
            'id' => $row->id,
            'event_type' => $row->event_type,
            'amount' => (int) $row->amount,
            'unit' => $row->unit,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ])->values());
    }

    public function packs(CoinEconomy $economy): JsonResponse
    {
        return ApiResponse::success($economy->presentPacks());
    }

    public function economy(CoinEconomy $economy): JsonResponse
    {
        $regime = $economy->active();

        return ApiResponse::success([
            'regime' => $regime ? $economy->presentRegime($regime, false) : null,
            'packs' => $economy->presentPacks(),
            'rules' => [
                'coins_per_diamond' => 1,
                'extra_platform_fee_on_cashout' => false,
                'transfer_fee_deducted_from_creator' => true,
            ],
        ]);
    }

    public function show(Request $request, string $gift): JsonResponse
    {
        $row = DB::table('gifts')->where('id', $gift)->where('sender_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Gift receipt not found.', 404);
        }

        return ApiResponse::success($this->presentGift($row, $this->walletBalance($request->user())));
    }

    public function send(Request $request, PostLedgerTransaction $post, CoinEconomy $economy): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Gifts are awaiting finance sign-off.', 503);
        }
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '') {
            return ApiResponse::error('VALIDATION', 'Idempotency-Key header is required.', 422, ['Idempotency-Key' => ['Required']]);
        }
        $regime = $economy->active();
        if (! $regime) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Diamond conversion is not configured.', 503);
        }
        $data = $request->validate(['gift_type_id' => ['required', 'exists:gift_types,id'], 'creator_profile_id' => ['required', 'exists:creator_profiles,id'], 'message' => ['nullable', 'string', 'max:280']]);
        $giftType = GiftType::findOrFail($data['gift_type_id']);
        $creator = CreatorProfile::findOrFail($data['creator_profile_id']);
        $sender = FinancialAccount::firstOrCreate(['owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'type' => 'gift_wallet', 'unit' => 'PCN'], ['balance' => 0]);
        $clearing = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'gift_coin_clearing', 'unit' => 'PCN'], ['balance' => 0]);
        $issuance = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'diamond_issuance', 'unit' => CoinEconomy::UNIT], ['balance' => 0]);
        $diamonds = FinancialAccount::firstOrCreate(['owner_type' => get_class($creator), 'owner_id' => $creator->id, 'type' => 'diamond_wallet', 'unit' => CoinEconomy::UNIT], ['balance' => 0]);
        $feeVersion = DB::table('fee_versions')->where('type', 'gift_platform')->where('active', true)->where('effective_at', '<=', now())->latest('effective_at')->first();
        try {
            $gift = DB::transaction(function () use ($post, $key, $giftType, $sender, $clearing, $issuance, $diamonds, $feeVersion, $regime, $request, $creator, $data): object {
                $coinTransaction = $post->handle('gift.sent', $key, 'PCN', [
                    ['account_id' => $sender->id, 'amount' => -$giftType->coins],
                    ['account_id' => $clearing->id, 'amount' => $giftType->coins],
                ], ['gift_type_id' => $giftType->id, 'creator_profile_id' => $creator->id, 'economy_regime_id' => $regime->id, 'coins_per_diamond' => 1]);
                $gift = DB::table('gifts')->where('coin_ledger_transaction_id', $coinTransaction->id)->first();
                if ($gift) {
                    return $gift;
                }
                $diamondTransaction = $post->handle('gift.sent', $key.':diamonds', CoinEconomy::UNIT, [
                    ['account_id' => $issuance->id, 'amount' => -$giftType->coins],
                    ['account_id' => $diamonds->id, 'amount' => $giftType->coins],
                ], ['gift_type_id' => $giftType->id, 'creator_profile_id' => $creator->id, 'economy_regime_id' => $regime->id, 'coins_per_diamond' => 1]);
                $id = (string) Str::ulid();
                DB::table('gifts')->insert([
                    'id' => $id,
                    'sender_id' => $request->user()->id,
                    'creator_profile_id' => $creator->id,
                    'gift_type_id' => $giftType->id,
                    'ledger_transaction_id' => $diamondTransaction->id,
                    'coin_ledger_transaction_id' => $coinTransaction->id,
                    'fee_version_id' => $feeVersion?->id,
                    'economy_regime_id' => $regime->id,
                    'message' => $data['message'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('creator_revenue_events')->insert([
                    'id' => (string) Str::ulid(),
                    'creator_profile_id' => $creator->id,
                    'source_type' => 'gift',
                    'source_id' => $id,
                    'ledger_transaction_id' => $diamondTransaction->id,
                    'fee_version_id' => $feeVersion?->id,
                    'unit' => CoinEconomy::UNIT,
                    'gross_amount' => $giftType->coins,
                    'fee_amount' => 0,
                    'net_amount' => $giftType->coins,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return DB::table('gifts')->find($id);
            }, 3);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_COINS') {
                return ApiResponse::error('INSUFFICIENT_COINS', 'Your gift wallet does not have enough coins.', 409);
            }
            if ($e->getMessage() === 'IDEMPOTENCY_CONFLICT') {
                return ApiResponse::error('CONFLICT', 'Idempotency key was already used for another operation.', 409);
            }
            throw $e;
        }

        return ApiResponse::success($this->presentGift($gift, $this->walletBalance($request->user())), status: 201);
    }

    private function walletBalance(object $user): int
    {
        return (int) (FinancialAccount::where('owner_type', get_class($user))->where('owner_id', $user->id)->where('type', 'gift_wallet')->where('unit', 'PCN')->value('balance') ?? 0);
    }

    private function presentGift(object $gift, int $balance): array
    {
        $type = GiftType::find($gift->gift_type_id);
        $creator = CreatorProfile::find($gift->creator_profile_id);
        $handle = $creator ? User::where('id', $creator->user_id)->value('handle') : null;

        return [
            'id' => $gift->id,
            'gift_type_id' => $gift->gift_type_id,
            'gift_type_name' => $type?->name,
            'coins' => (int) ($type?->coins ?? 0),
            'diamonds' => (int) ($type?->coins ?? 0),
            'creator_profile_id' => $gift->creator_profile_id,
            'creator_display_name' => $creator?->display_name,
            'creator_handle' => $handle,
            'message' => $gift->message,
            'remaining_balance' => $balance,
            'created_at' => Carbon::parse($gift->created_at)->toIso8601String(),
        ];
    }
}
