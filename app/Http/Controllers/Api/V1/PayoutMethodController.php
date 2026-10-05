<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\PayoutDestinationVerifier;
use App\Support\AgeMajority;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class PayoutMethodController extends Controller
{
    private const PUBLIC_COLUMNS = ['id', 'provider', 'kind', 'label', 'destination_last_four', 'verified_at', 'proof_status', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('payout_methods')->where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->select(self::PUBLIC_COLUMNS)->latest()->get());
    }

    public function store(Request $request, PayoutDestinationVerifier $verifier): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Payout methods are awaiting finance sign-off.', 503);
        }
        if ($denied = AgeMajority::missing($request->user())) {
            return $denied;
        }
        $data = $request->validate([
            'provider' => ['required', 'in:paystack,paypal'],
            'kind' => ['required', 'in:bank,paypal'],
            'destination' => ['required', 'array'],
            'destination.account_number' => ['required_if:kind,bank', 'digits_between:8,12'],
            'destination.bank_code' => ['required_if:kind,bank', 'string', 'max:20'],
            'destination.email' => ['required_if:kind,paypal', 'email:rfc', 'max:191'],
        ]);
        if (($data['provider'] === 'paystack') !== ($data['kind'] === 'bank')) {
            return ApiResponse::error('VALIDATION', 'Provider and payout method type do not match.', 422);
        }
        try {
            $verification = $verifier->verify($data['provider'], $data['kind'], $data['destination']);
        } catch (Throwable) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Payout destination verification is unavailable.', 503);
        }
        $display = $data['kind'] === 'bank' ? $data['destination']['account_number'] : $data['destination']['email'];
        $id = (string) Str::ulid();
        $bank = $data['kind'] === 'bank';
        DB::table('payout_methods')->insert([
            'id' => $id,
            'owner_type' => get_class($request->user()),
            'owner_id' => $request->user()->id,
            'provider' => $data['provider'],
            'kind' => $data['kind'],
            'label' => $verification['label'],
            'destination_encrypted' => encrypt($data['destination']),
            'destination_last_four' => mb_substr($display, -4),
            'verification_reference' => $verification['reference'],
            'verified_at' => $bank ? null : ($verification['verified'] ? now() : null),
            'proof_status' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ApiResponse::success($this->present($id), status: 201);
    }

    public function uploadAccountProof(Request $request, string $payoutMethod): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Payout methods are awaiting finance sign-off.', 503);
        }
        if ($denied = AgeMajority::missing($request->user())) {
            return $denied;
        }
        $data = $request->validate([
            'account_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);
        $row = DB::table('payout_methods')->where('id', $payoutMethod)->where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Payout method not found.', 404);
        }
        if ($row->kind !== 'bank') {
            return ApiResponse::error('VALIDATION', 'Account proof is only required for bank payout methods.', 422);
        }

        $file = $data['account_proof'];
        $path = $file->store('accounts', 'proofs');
        $previous = ['path' => $row->proof_path, 'disk' => $row->proof_disk ?: 'proofs'];
        try {
            DB::table('payout_methods')->where('id', $payoutMethod)->update([
                'proof_path' => $path,
                'proof_disk' => 'proofs',
                'proof_mime' => $file->getMimeType() ?: 'application/octet-stream',
                'proof_status' => 'pending',
                'verified_at' => null,
                'proof_reviewed_at' => null,
                'proof_reviewed_by' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('proofs')->delete($path);
            throw $exception;
        }
        if ($previous['path'] && $previous['path'] !== $path && Storage::disk($previous['disk'])->exists($previous['path'])) {
            Storage::disk($previous['disk'])->delete($previous['path']);
        }

        return ApiResponse::success($this->present($payoutMethod));
    }

    private function present(string $id): ?object
    {
        return DB::table('payout_methods')->where('id', $id)->select(self::PUBLIC_COLUMNS)->first();
    }
}
