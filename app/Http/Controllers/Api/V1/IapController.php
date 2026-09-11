<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Integrations\Payments\StoreReceiptVerifier;
use App\Models\FinancialAccount;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class IapController extends Controller
{
    public function store(Request $request, StoreReceiptVerifier $verifier, PostLedgerTransaction $post): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Financial purchases are awaiting finance sign-off.', 503);
        }
        $data = $request->validate(['store' => ['required', 'in:apple,google'], 'receipt' => ['required', 'string', 'max:20000']]);
        try {
            $verified = $verifier->verify($data['store'], $data['receipt']);
        } catch (RuntimeException $exception) {
            return ApiResponse::error($exception->getMessage() === 'IAP_UNVERIFIED' ? 'IAP_UNVERIFIED' : 'SERVICE_DEGRADED', 'The store receipt could not be verified.', 422);
        }

        return DB::transaction(function () use ($request, $data, $verified, $post): JsonResponse {
            $existing = DB::table('iap_receipts')->where('store', $data['store'])->where('original_transaction_id', $verified['original_transaction_id'])->lockForUpdate()->first();
            if ($existing) {
                return $existing->user_id === $request->user()->id
                    ? ApiResponse::success($this->presentReceipt($existing, $this->walletBalance($request->user())))
                    : ApiResponse::error('CONFLICT', 'This receipt has already been claimed.', 409);
            }
            $product = DB::table('coin_products')->where('store', $data['store'])->where('product_id', $verified['product_id'])->where('active', true)->first();
            if (! $product) {
                return ApiResponse::error('IAP_UNVERIFIED', 'The verified store product is not available.', 422);
            }
            $wallet = FinancialAccount::firstOrCreate(['owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'type' => 'gift_wallet', 'unit' => $product->unit], ['balance' => 0]);
            $liability = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'iap_liability', 'unit' => $product->unit], ['balance' => 0]);
            $transaction = $post->handle('iap.verified', "iap:{$data['store']}:{$verified['original_transaction_id']}", $product->unit, [['account_id' => $liability->id, 'amount' => -$product->coins], ['account_id' => $wallet->id, 'amount' => $product->coins]], ['store' => $data['store'], 'product_id' => $product->product_id]);
            $id = (string) Str::ulid();
            DB::table('iap_receipts')->insert(['id' => $id, 'user_id' => $request->user()->id, 'coin_product_id' => $product->id, 'ledger_transaction_id' => $transaction->id, 'store' => $data['store'], 'original_transaction_id' => $verified['original_transaction_id'], 'receipt_hash' => hash('sha256', $data['receipt']), 'state' => 'verified', 'verification_payload' => json_encode(['environment' => $verified['environment'] ?? null], JSON_THROW_ON_ERROR), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success($this->presentReceipt(DB::table('iap_receipts')->find($id), $this->walletBalance($request->user())), status: 201);
        }, 3);
    }

    private function walletBalance(object $user): int
    {
        return (int) (FinancialAccount::where('owner_type', get_class($user))->where('owner_id', $user->id)->where('type', 'gift_wallet')->where('unit', 'PCN')->value('balance') ?? 0);
    }

    private function presentReceipt(object $row, int $balance): array
    {
        $product = DB::table('coin_products')->where('id', $row->coin_product_id)->first();

        return [
            'id' => $row->id,
            'store' => $row->store,
            'product_id' => $product->product_id ?? null,
            'coins' => (int) ($product->coins ?? 0),
            'unit' => $product->unit ?? 'PCN',
            'state' => $row->state,
            'remaining_balance' => $balance,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
