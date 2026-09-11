<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InvitationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($request, DB::table('user_invitations')->where('user_id', $request->user()->id)->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest()->first()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['rotate' => ['sometimes', 'boolean']]);
        $invite = DB::transaction(function () use ($request, $data): object {
            $current = DB::table('user_invitations')->where('user_id', $request->user()->id)->whereNull('revoked_at')->lockForUpdate()->latest()->first();
            if ($current && ! ($data['rotate'] ?? false)) {
                return $current;
            }
            if ($current) {
                DB::table('user_invitations')->where('id', $current->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            $id = (string) Str::ulid();
            DB::table('user_invitations')->insert(['id' => $id, 'user_id' => $request->user()->id, 'code' => Str::upper(Str::random(10)), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('user_invitations')->where('id', $id)->first();
        });

        return ApiResponse::success($this->present($request, $invite), status: 201);
    }

    public function redeem(Request $request): JsonResponse
    {
        if (! config('features.referrals')) {
            return ApiResponse::error('FORBIDDEN', 'Referrals are disabled.', 403);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $invite = DB::table('user_invitations')->where('code', Str::upper($data['code']))->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->lockForUpdate()->first();
            if (! $invite || $invite->user_id === $request->user()->id || $invite->redemptions >= $invite->max_redemptions) {
                return ApiResponse::error('INVITE_INVALID', 'The invitation is invalid or expired.', 422);
            }
            if (DB::table('referrals')->where('referred_id', $request->user()->id)->exists()) {
                return ApiResponse::error('REFERRAL_ALREADY_REDEEMED', 'An invitation has already been redeemed.', 409);
            }
            DB::table('referrals')->insert(['id' => (string) Str::ulid(), 'referrer_id' => $invite->user_id, 'referred_id' => $request->user()->id, 'code' => $invite->code.'-'.Str::lower(Str::random(6)), 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_invitations')->where('id', $invite->id)->increment('redemptions');

            return ApiResponse::success(['redeemed' => true], status: 201);
        });
    }

    private function present(Request $request, ?object $invite): array
    {
        return [
            'user_id' => $request->user()->id, 'code' => $invite?->code,
            'expires_at' => $invite?->expires_at ? Carbon::parse($invite->expires_at)->toIso8601String() : null,
            'redemptions' => (int) ($invite?->redemptions ?? 0), 'max_redemptions' => (int) ($invite?->max_redemptions ?? 0),
        ];
    }
}
