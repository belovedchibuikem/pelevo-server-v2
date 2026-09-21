<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:fcm,apns'],
            'token' => ['required', 'string', 'min:16', 'max:4096'],
        ]);
        $deviceId = $request->header('X-Device-Id');
        if (! is_string($deviceId) || $deviceId === '') {
            return ApiResponse::error('FORBIDDEN', 'A registered active device is required.', 403);
        }
        $device = Device::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where(function ($query) use ($deviceId): void {
                $query->whereKey($deviceId)->orWhere('device_identifier', $deviceId);
            })
            ->first();
        if (! $device) {
            return ApiResponse::error('FORBIDDEN', 'A registered active device is required.', 403);
        }
        $hash = hash('sha256', $data['provider'].'|'.$data['token']);
        $existing = DB::table('push_tokens')->where('token_hash', $hash)->first();
        $values = [
            'user_id' => $request->user()->id,
            'device_id' => $device->id,
            'provider' => $data['provider'],
            'token_encrypted' => Crypt::encryptString($data['token']),
            'revoked_at' => null,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('push_tokens')->where('id', $existing->id)->update($values);
        } else {
            DB::table('push_tokens')->insert([
                ...$values,
                'id' => (string) Str::ulid(),
                'token_hash' => $hash,
                'created_at' => now(),
            ]);
        }

        return ApiResponse::success([
            'registered' => true,
            'provider' => $data['provider'],
            'device_id' => $device->id,
        ], status: 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:fcm,apns'],
            'token' => ['required', 'string', 'min:16', 'max:4096'],
        ]);
        $hash = hash('sha256', $data['provider'].'|'.$data['token']);
        DB::table('push_tokens')
            ->where('user_id', $request->user()->id)
            ->where('token_hash', $hash)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['revoked' => true]);
    }
}
