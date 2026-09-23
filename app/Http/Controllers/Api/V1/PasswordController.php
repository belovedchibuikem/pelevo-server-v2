<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\OneTimeCodeService;
use App\Http\Controllers\Controller;
use App\Mail\OneTimeCode;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\MailPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class PasswordController extends Controller
{
    public function forgot(Request $request, OneTimeCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower($data['email']);
        if (User::where('email', $email)->exists()) {
            app(MailPreference::class)->queueTransactional($email, new OneTimeCode($codes->issue($email, 'password_reset'), 'password_reset'));
        }

        return ApiResponse::success(['accepted' => true]);
    }

    public function reset(Request $request, OneTimeCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6'], 'password' => ['required', 'confirmed', Password::defaults()]]);
        $email = Str::lower($data['email']);
        $user = User::where('email', $email)->first();
        if (! $user || ! $codes->consume($email, 'password_reset', $data['code'])) {
            return ApiResponse::error('UNAUTHENTICATED', 'The verification code is invalid or expired.', 401);
        }
        $user->update(['password' => Hash::make($data['password'])]);
        RefreshToken::where('user_id', $user->id)->update(['revoked_at' => now()]);
        $user->tokens()->delete();

        return ApiResponse::success(['password_reset' => true]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', Password::defaults()]]);
        $request->user()->update(['password' => Hash::make($data['password'])]);
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();

        return ApiResponse::success(['password_changed' => true]);
    }
}
