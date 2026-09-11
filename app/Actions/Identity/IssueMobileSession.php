<?php

namespace App\Actions\Identity;

use App\Models\Device;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class IssueMobileSession
{
    public function handle(User $user, Request $request, string $deviceName, ?Device $device = null): array
    {
        $identifier = (string) ($request->header('X-Device-Id') ?: Str::uuid());
        $device ??= Device::updateOrCreate(['user_id' => $user->id, 'device_identifier' => $identifier], ['name' => $deviceName, 'platform' => $request->header('X-Platform'), 'last_seen_at' => now(), 'revoked_at' => null]);
        $plain = Str::random(80);
        RefreshToken::create(['user_id' => $user->id, 'device_id' => $device->id, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(30)]);
        $access = $user->createToken($device->id, ['mobile'], now()->addMinutes(15))->plainTextToken;

        return ['user' => $user, 'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 900, 'refresh_token' => $plain, 'device_id' => $device->id];
    }
}
