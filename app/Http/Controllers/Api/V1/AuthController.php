<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\GrantWelcomeBonus;
use App\Actions\Identity\IssueMobileSession;
use App\Actions\Identity\OneTimeCodeService;
use App\Http\Controllers\Controller;
use App\Mail\OneTimeCode;
use App\Mail\PelevoNotice;
use App\Models\Device;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\MailPreference;
use App\Support\ApiResponse;
use App\Support\SignupCountry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class AuthController extends Controller
{
    public function register(Request $request, OneTimeCodeService $codes, SignupCountry $countries): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'handle' => ['sometimes', 'nullable', 'alpha_dash:ascii', 'min:3', 'max:30', 'unique:users,handle'], 'email' => ['required', 'email', 'max:254', 'unique:users'], 'password' => ['required', 'confirmed', Password::defaults()], 'device_name' => ['required', 'string', 'max:100'], 'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha']]);

        $user = DB::transaction(function () use ($data, $request, $countries): User {
            $user = User::create([
                'name' => $data['name'],
                'handle' => $data['handle'] ?? null,
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'email_verified_at' => null,
            ]);
            $countries->capture($user, $request, $data['country_code'] ?? null);

            return $user;
        });
        $this->sendVerificationCode($codes, $user->email);

        return ApiResponse::success([
            'verification_required' => true,
            'email' => $user->email,
        ], status: 201);
    }

    public function login(Request $request, IssueMobileSession $sessions, OneTimeCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:100'], 'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha']]);
        $user = User::where('email', Str::lower($data['email']))->first();
        if (! $user || $user->status !== 'active' || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('UNAUTHENTICATED', 'The supplied credentials are invalid.', 401);
        }
        if ($user->email_verified_at === null) {
            $this->sendVerificationCode($codes, $user->email);

            return ApiResponse::error('EMAIL_UNVERIFIED', 'Verify your email to activate this account. We sent a new code.', 403);
        }

        $payload = $sessions->handle($user, $request, $data['device_name']);
        app(SignupCountry::class)->capture($user, $request, $data['country_code'] ?? null);
        $grant = app(GrantWelcomeBonus::class)->handle($user);
        if ($grant !== null) {
            $payload['welcome_bonus'] = $grant;
        }

        return ApiResponse::success($payload);
    }

    public static function sendVerificationCode(OneTimeCodeService $codes, string $email): void
    {
        app(MailPreference::class)->queueTransactional(
            $email,
            new OneTimeCode($codes->issue($email, 'email_verification'), 'email_verification'),
        );
    }

    public static function sendWelcome(User $user, ?int $welcomeCoins = null): void
    {
        $coins = $welcomeCoins !== null && $welcomeCoins > 0
            ? ' '.$welcomeCoins.' welcome coins are in your gift wallet. They can be gifted to creators and cannot be cashed out.'
            : '';
        app(MailPreference::class)->queueTransactional($user->email, new PelevoNotice(
            subjectLine: 'Welcome to Pelevo',
            eyebrow: 'Welcome',
            heading: 'Your African podcast home',
            intro: 'Hi '.$user->name.', your Pelevo account is ready.'.$coins.' Follow shows you love and we will email you when new episodes drop, as long as Email Notifications stays on.',
            actionLabel: 'Open Pelevo',
            actionUrl: config('app.url'),
        ));
    }

    public function refresh(Request $request, IssueMobileSession $sessions): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string'], 'device_id' => ['required', 'string']]);

        return DB::transaction(function () use ($data, $request, $sessions): JsonResponse {
            $token = RefreshToken::where('token_hash', hash('sha256', $data['refresh_token']))->lockForUpdate()->first();
            if (! $token || $token->device_id !== $data['device_id'] || $token->used_at || $token->revoked_at || $token->expires_at->isPast()) {
                if ($token?->used_at) {
                    RefreshToken::where('device_id', $token->device_id)->update(['revoked_at' => now()]);
                }

                return ApiResponse::error('UNAUTHENTICATED', 'The refresh token is invalid or expired.', 401);
            }
            $token->update(['used_at' => now()]);
            $device = Device::findOrFail($token->device_id);
            $user = User::findOrFail($token->user_id);
            if ($user->status !== 'active' || $device->revoked_at || $device->user_id !== $user->id) {
                RefreshToken::where('device_id', $device->id)->update(['revoked_at' => now()]);

                return ApiResponse::error('UNAUTHENTICATED', 'The session is no longer available.', 401);
            }

            return ApiResponse::success($sessions->handle($user, $request, $device->name ?? 'device', $device));
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        if ($deviceId = $request->header('X-Device-Id')) {
            $device = Device::where('user_id', $request->user()->id)->where(function ($query) use ($deviceId): void {
                $query->whereKey($deviceId)->orWhere('device_identifier', $deviceId);
            })->first();
            if ($device) {
                RefreshToken::where('device_id', $device->id)->update(['revoked_at' => now()]);
                $device->update(['revoked_at' => now()]);
            }
        }

        return ApiResponse::success(['logged_out' => true]);
    }
}
