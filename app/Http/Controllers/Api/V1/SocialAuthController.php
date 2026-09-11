<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\IssueMobileSession;
use App\Contracts\SocialIdentityVerifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SocialAuthController extends Controller
{
    public function __invoke(Request $request, SocialIdentityVerifier $verifier, IssueMobileSession $sessions): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', 'in:apple,google'], 'token' => ['required', 'string', 'max:10000'], 'device_name' => ['required', 'string', 'max:100']]);
        $identity = $verifier->verify($data['provider'], $data['token']);
        if (! $identity) {
            return ApiResponse::error('SOCIAL_TOKEN_INVALID', 'The provider token could not be verified.', 401);
        }
        $knownIdentity = DB::table('connected_accounts')->where('provider', $data['provider'])->where('provider_subject', $identity->subject)->exists();
        if (! $knownIdentity && (! $identity->email || ! $identity->emailVerified)) {
            return ApiResponse::error('SOCIAL_EMAIL_UNVERIFIED', 'A verified provider email is required for first sign-in.', 422);
        }

        try {
            $user = DB::transaction(function () use ($identity, $data): User {
                $account = DB::table('connected_accounts')->where('provider', $data['provider'])->where('provider_subject', $identity->subject)->lockForUpdate()->first();
                if ($account) {
                    return User::whereKey($account->user_id)->where('status', 'active')->firstOrFail();
                }
                $user = User::firstOrCreate(['email' => $identity->email], ['name' => $identity->name ?: Str::before($identity->email, '@'), 'password' => Str::random(64), 'email_verified_at' => now()]);
                DB::table('connected_accounts')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'provider' => $data['provider'], 'provider_subject' => $identity->subject, 'email' => $identity->email, 'created_at' => now(), 'updated_at' => now()]);

                return $user;
            });
        } catch (QueryException) {
            return ApiResponse::error('SOCIAL_ACCOUNT_CONFLICT', 'This provider identity is already linked.', 409);
        }

        return ApiResponse::success($sessions->handle($user, $request, $data['device_name']));
    }
}
