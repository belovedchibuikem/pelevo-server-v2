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
            'provider' => ['required', 'in:paystack,flutterwave,paypal'],
            'kind' => ['required', 'in:bank,paypal'],
            'destination' => ['required', 'array'],
            'destination.account_number' => ['required_if:kind,bank', 'digits_between:8,12'],
            'destination.bank_code' => ['required_if:kind,bank', 'string', 'max:20'],
            'destination.country' => ['required_if:provider,flutterwave', 'nullable', 'string', 'size:2'],
            'destination.account_name' => ['nullable', 'string', 'max:191'],
            'destination.email' => ['required_if:kind,paypal', 'email', 'max:191'],
        ]);
        $bankProvider = in_array($data['provider'], ['paystack', 'flutterwave'], true);
        if ($bankProvider !== ($data['kind'] === 'bank') || ($data['provider'] === 'paypal') !== ($data['kind'] === 'paypal')) {
            return ApiResponse::error('VALIDATION', 'Provider and payout method type do not match.', 422);
        }
        if ($data['kind'] === 'paypal') {
            $data['destination']['email'] = strtolower(trim((string) $data['destination']['email']));
        }
        try {
            $verification = $verifier->verify($data['provider'], $data['kind'], $data['destination']);
        } catch (Throwable) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Payout destination verification is unavailable.', 503);
        }
        if ($data['provider'] === 'flutterwave' && $data['kind'] === 'bank' && ! ($verification['verified'] ?? false)) {
            return ApiResponse::error('VALIDATION', 'We could not verify that bank account. Check the country, bank, and account number.', 422);
        }
        if ($data['kind'] === 'paypal' && ! ($verification['verified'] ?? false)) {
            return ApiResponse::error('VALIDATION', 'Enter a valid PayPal email address.', 422);
        }
        if (is_string($verification['label'] ?? null) && $verification['label'] !== '') {
            if ($data['kind'] === 'bank') {
                $data['destination']['account_name'] = $verification['label'];
            } else {
                $data['destination']['email'] = strtolower(trim((string) ($data['destination']['email'] ?? $verification['label'])));
            }
        }
        $display = $data['kind'] === 'bank'
            ? (string) $data['destination']['account_number']
            : (string) $data['destination']['email'];
        $lastFour = $this->destinationLastFour($data['kind'], $display);
        $verifiedNow = ($verification['verified'] ?? false) === true;
        $ownerType = get_class($request->user());
        $ownerId = $request->user()->id;
        $existing = DB::table('payout_methods')
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('provider', $data['provider'])
            ->where('kind', $data['kind'])
            ->orderByDesc('created_at')
            ->first();

        if ($existing) {
            $previousProof = ['path' => $existing->proof_path, 'disk' => $existing->proof_disk ?: 'proofs'];
            DB::table('payout_methods')->where('id', $existing->id)->update([
                'label' => $verification['label'] ?? ($data['destination']['account_name'] ?? $existing->label),
                'destination_encrypted' => encrypt($data['destination']),
                'destination_last_four' => $lastFour,
                'verification_reference' => $verification['reference'],
                'verified_at' => $verifiedNow ? now() : null,
                'proof_status' => 'none',
                'proof_path' => null,
                'proof_disk' => null,
                'proof_mime' => null,
                'proof_reviewed_at' => null,
                'proof_reviewed_by' => null,
                'updated_at' => now(),
            ]);
            if ($previousProof['path'] && Storage::disk($previousProof['disk'])->exists($previousProof['path'])) {
                Storage::disk($previousProof['disk'])->delete($previousProof['path']);
            }
            $this->pruneUnreferencedDuplicates($ownerType, $ownerId, $data['provider'], $data['kind'], $existing->id);

            return ApiResponse::success($this->present($existing->id));
        }

        $id = (string) Str::ulid();
        DB::table('payout_methods')->insert([
            'id' => $id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'provider' => $data['provider'],
            'kind' => $data['kind'],
            'label' => $verification['label'] ?? ($data['destination']['account_name'] ?? null),
            'destination_encrypted' => encrypt($data['destination']),
            'destination_last_four' => $lastFour,
            'verification_reference' => $verification['reference'],
            'verified_at' => $verifiedNow ? now() : null,
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
                // Keep name-enquiry / PayPal auto-verification so withdrawals stay available.
                'verified_at' => $row->verified_at,
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

    private function destinationLastFour(string $kind, string $display): string
    {
        if ($kind === 'paypal') {
            $local = Str::before($display, '@');
            $source = $local !== '' ? $local : $display;

            return mb_substr($source, -4);
        }

        return mb_substr($display, -4);
    }

    private function pruneUnreferencedDuplicates(string $ownerType, int|string $ownerId, string $provider, string $kind, string $keepId): void
    {
        $candidates = DB::table('payout_methods')
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('provider', $provider)
            ->where('kind', $kind)
            ->where('id', '!=', $keepId)
            ->pluck('id');
        foreach ($candidates as $candidateId) {
            $inUse = false;
            foreach (['withdrawals', 'creator_payouts', 'diamond_cashouts', 'creator_payout_settings'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->where('payout_method_id', $candidateId)->exists()) {
                    $inUse = true;
                    break;
                }
            }
            if ($inUse) {
                continue;
            }
            $row = DB::table('payout_methods')->where('id', $candidateId)->first();
            if ($row?->proof_path) {
                $disk = $row->proof_disk ?: 'proofs';
                if (Storage::disk($disk)->exists($row->proof_path)) {
                    Storage::disk($disk)->delete($row->proof_path);
                }
            }
            DB::table('payout_methods')->where('id', $candidateId)->delete();
        }
    }

    private function present(string $id): ?object
    {
        return DB::table('payout_methods')->where('id', $id)->select(self::PUBLIC_COLUMNS)->first();
    }
}
