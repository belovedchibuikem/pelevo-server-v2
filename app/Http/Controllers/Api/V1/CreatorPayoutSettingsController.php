<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreatorPayoutSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->firstOrFail();

        return ApiResponse::success(['settings' => DB::table('creator_payout_settings')->where('creator_profile_id', $creator->id)->first(), 'tax_profile' => DB::table('tax_profiles')->where('creator_profile_id', $creator->id)->select('id', 'country_code', 'state', 'updated_at')->first()]);
    }

    public function update(Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->firstOrFail();
        $data = $request->validate([
            'payout_method_id' => ['sometimes', 'string'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'schedule' => ['sometimes', 'in:weekly,monthly,manual'],
            'minimum_amount' => ['sometimes', 'integer', 'min:1000'],
            'country_code' => ['required_with:tax_details', 'string', 'size:2'],
            'tax_details' => ['sometimes', 'array'],
        ]);
        if ($data === []) {
            return ApiResponse::error('UNPROCESSABLE', 'No payout settings were provided.', 422);
        }
        $existing = DB::table('creator_payout_settings')->where('creator_profile_id', $creator->id)->first();
        $methodId = $data['payout_method_id'] ?? $existing?->payout_method_id;
        if (array_key_exists('payout_method_id', $data)) {
            $method = DB::table('payout_methods')->where('id', $data['payout_method_id'])->where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->whereNotNull('verified_at')->first();
            if (! $method) {
                return ApiResponse::error('PAYOUT_METHOD_UNVERIFIED', 'A verified payout method owned by you is required.', 409);
            }
        }
        $resetsCompliance = array_key_exists('payout_method_id', $data) || array_key_exists('tax_details', $data);
        DB::transaction(function () use ($creator, $data, $existing, $methodId, $resetsCompliance): void {
            $settings = [
                'payout_method_id' => $methodId,
                'currency' => strtoupper($data['currency'] ?? $existing?->currency ?? 'USD'),
                'schedule' => $data['schedule'] ?? $existing?->schedule ?? 'monthly',
                'minimum_amount' => $data['minimum_amount'] ?? $existing?->minimum_amount ?? 1000,
                'compliance_state' => $resetsCompliance ? 'pending' : ($existing?->compliance_state ?? 'pending'),
                'verified_at' => $resetsCompliance ? null : $existing?->verified_at,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('creator_payout_settings')->where('creator_profile_id', $creator->id)->update($settings);
            } else {
                DB::table('creator_payout_settings')->insert(['creator_profile_id' => $creator->id, ...$settings, 'created_at' => now()]);
            }
            if (! array_key_exists('tax_details', $data)) {
                return;
            }
            $tax = ['country_code' => strtoupper($data['country_code']), 'state' => 'pending', 'details_encrypted' => encrypt(json_encode($data['tax_details'], JSON_THROW_ON_ERROR)), 'updated_at' => now()];
            if (DB::table('tax_profiles')->where('creator_profile_id', $creator->id)->exists()) {
                DB::table('tax_profiles')->where('creator_profile_id', $creator->id)->update($tax);
            } else {
                DB::table('tax_profiles')->insert([...$tax, 'id' => (string) Str::ulid(), 'creator_profile_id' => $creator->id, 'created_at' => now()]);
            }
        });

        return $this->show($request);
    }
}
