<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\PayoutDestinationVerifier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PayoutMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('payout_methods')->where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->select('id', 'provider', 'kind', 'label', 'destination_last_four', 'verified_at', 'created_at')->latest()->get());
    }

    public function store(Request $request, PayoutDestinationVerifier $verifier): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Payout methods are awaiting finance sign-off.', 503);
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
        DB::table('payout_methods')->insert(['id' => $id, 'owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'provider' => $data['provider'], 'kind' => $data['kind'], 'label' => $verification['label'], 'destination_encrypted' => encrypt($data['destination']), 'destination_last_four' => mb_substr($display, -4), 'verification_reference' => $verification['reference'], 'verified_at' => $verification['verified'] ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(DB::table('payout_methods')->where('id', $id)->select('id', 'provider', 'kind', 'label', 'destination_last_four', 'verified_at')->first(), status: 201);
    }
}
