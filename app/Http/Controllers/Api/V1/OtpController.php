<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\IssueMobileSession;
use App\Actions\Identity\OneTimeCodeService;
use App\Http\Controllers\Controller;
use App\Mail\OneTimeCode;
use App\Models\User;
use App\Services\MailPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class OtpController extends Controller
{
    public function send(Request $request, OneTimeCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'purpose' => ['required', 'in:login,password_reset,email_verification']]);
        $email = Str::lower($data['email']);
        if (User::where('email', $email)->exists()) {
            app(MailPreference::class)->queueTransactional($email, new OneTimeCode($codes->issue($email, $data['purpose']), $data['purpose']));
        }

        return ApiResponse::success(['accepted' => true]);
    }

    public function verify(Request $request, OneTimeCodeService $codes, IssueMobileSession $sessions): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6'], 'purpose' => ['required', 'in:login,email_verification'], 'device_name' => ['required', 'string', 'max:100']]);
        $email = Str::lower($data['email']);
        $user = User::where('email', $email)->first();
        if (! $user || $user->status !== 'active' || ! $codes->consume($email, $data['purpose'], $data['code'])) {
            return ApiResponse::error('UNAUTHENTICATED', 'The verification code is invalid or expired.', 401);
        }
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            AuthController::sendWelcome($user);
        }

        return ApiResponse::success($sessions->handle($user, $request, $data['device_name']));
    }
}
