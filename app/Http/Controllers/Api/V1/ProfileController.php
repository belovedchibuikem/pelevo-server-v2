<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\OnboardingCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ProfileController extends Controller
{
    public function avatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=2048,max_height=2048']]);
        if (! function_exists('imagecreatefromstring')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Photo processing is unavailable. Please try again later.', 503);
        }
        $source = imagecreatefromstring($request->file('avatar')->get());
        if ($source === false) {
            return ApiResponse::error('VALIDATION', 'The photo could not be read.', 422, ['avatar' => ['Choose a valid JPEG, PNG or WebP image.']]);
        }
        // Re-encode into a non-active format and discard uploaded metadata.
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagejpeg($source, $stream, 88)) {
                return ApiResponse::error('SERVICE_DEGRADED', 'The photo could not be processed.', 503);
            }
            rewind($stream);
            $path = 'avatars/'.$request->user()->id.'/'.Str::uuid().'.jpg';
            if (! Storage::disk('public')->put($path, $stream)) {
                return ApiResponse::error('SERVICE_DEGRADED', 'The photo could not be stored.', 503);
            }
        } finally {
            imagedestroy($source);
            fclose($stream);
        }
        $url = '/storage/'.$path;
        $previousUrl = null;
        try {
            DB::transaction(function () use ($request, $url, &$previousUrl): void {
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $previousUrl = DB::table('user_profiles')->where('user_id', $request->user()->id)->value('avatar_url');
                $exists = DB::table('user_profiles')->where('user_id', $request->user()->id)->exists();
                DB::table('user_profiles')->updateOrInsert(['user_id' => $request->user()->id], [
                    'avatar_url' => $url,
                    'updated_at' => now(),
                    ...($exists ? [] : ['created_at' => now()]),
                ]);
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        }
        $ownedPrefix = '/storage/avatars/'.$request->user()->id.'/';
        if (is_string($previousUrl) && str_starts_with($previousUrl, $ownedPrefix)
            && preg_match('/^[a-f0-9-]{36}\.jpg$/', substr($previousUrl, strlen($ownedPrefix)))) {
            Storage::disk('public')->delete(substr($previousUrl, strlen('/storage/')));
        }

        return ApiResponse::success(['avatar_url' => $url]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'handle' => ['sometimes', 'nullable', 'alpha_dash:ascii', 'min:3', 'max:30', Rule::unique('users')->ignore($user->id)], 'phone' => ['sometimes', 'nullable', 'string', 'max:25', Rule::unique('users')->ignore($user->id)], 'country_code' => ['sometimes', 'nullable', 'string', 'size:2'], 'bio' => ['sometimes', 'nullable', 'string', 'max:500'], 'locale' => ['sometimes', 'string', 'max:15'], 'timezone' => ['sometimes', 'timezone']]);
        DB::transaction(function () use ($user, $data): void {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->update(collect($data)->only(['name', 'handle', 'phone', 'country_code'])->all());
            $profile = collect($data)->only(['bio', 'locale', 'timezone'])->all();
            if ($profile !== []) {
                $existing = DB::table('user_profiles')->where('user_id', $user->id)->exists();
                DB::table('user_profiles')->updateOrInsert(['user_id' => $user->id], [...$profile, 'updated_at' => now(), ...($existing ? [] : ['created_at' => now()])]);
            }
        });

        return ApiResponse::success(['user' => $user->fresh(), 'profile' => DB::table('user_profiles')->where('user_id', $user->id)->first()]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $row = DB::table('user_preferences')->where('user_id', $request->user()->id)->first();

        $values = $row ? (array) $row : ['user_id' => $request->user()->id, 'explicit_content' => false, 'autoplay' => true, 'download_wifi_only' => true, 'data_saver' => false, 'audio_quality' => 'high', 'email_product' => false, 'email_episodes' => false, 'email_marketing' => false, 'version' => 0];
        foreach (['explicit_content', 'autoplay', 'download_wifi_only', 'data_saver', 'email_product', 'email_episodes', 'email_marketing'] as $key) {
            $values[$key] = (bool) $values[$key];
        }
        $values['version'] = (int) $values['version'];

        return ApiResponse::success($values);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'explicit_content' => ['sometimes', 'boolean'], 'autoplay' => ['sometimes', 'boolean'], 'download_wifi_only' => ['sometimes', 'boolean'], 'data_saver' => ['sometimes', 'boolean'], 'audio_quality' => ['sometimes', Rule::in(['low', 'normal', 'high', 'very_high'])], 'email_product' => ['sometimes', 'boolean'], 'email_episodes' => ['sometimes', 'boolean'], 'email_marketing' => ['sometimes', 'boolean']]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            // Serialize first writes too: a missing preferences row cannot be locked.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('user_preferences')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            $currentVersion = (int) ($row?->version ?? 0);
            if ((int) $data['version'] !== $currentVersion) {
                return ApiResponse::error('VERSION_CONFLICT', 'Preferences changed on another device.', 409);
            }
            $values = [...collect($data)->except('version')->all(), 'version' => $currentVersion + 1, 'updated_at' => now()];
            if ($row) {
                DB::table('user_preferences')->where('user_id', $request->user()->id)->update($values);
            } else {
                DB::table('user_preferences')->insert(['user_id' => $request->user()->id, ...$values, 'created_at' => now()]);
            }

            return $this->preferences($request);
        });
    }

    public function onboarding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'interests' => ['required', 'array', 'min:1', 'max:20'],
            'interests.*' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'locale' => ['sometimes', 'string', Rule::in(OnboardingCatalog::LOCALES)],
        ]);
        $normalized = [];
        foreach ($data['interests'] as $interest) {
            $canonical = OnboardingCatalog::normalizeInterest($interest);
            if ($canonical === null) {
                return ApiResponse::error('VALIDATION', 'Choose interests from the available list.', 422, ['interests' => ['Choose interests from the available list.']]);
            }
            $normalized[] = $canonical;
        }
        $normalized = array_values(array_unique($normalized));

        return DB::transaction(function () use ($request, $data, $normalized): JsonResponse {
            $user = $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('user_interests')->where('user_id', $user->id)->delete();
            DB::table('user_interests')->insert(collect($normalized)->map(fn (string $interest): array => [
                'user_id' => $user->id,
                'interest' => $interest,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
            $user->update(['onboarded_at' => now()]);
            $locale = $data['locale'] ?? null;
            if (is_string($locale)) {
                $exists = DB::table('user_profiles')->where('user_id', $user->id)->exists();
                DB::table('user_profiles')->updateOrInsert(
                    ['user_id' => $user->id],
                    ['locale' => $locale, 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])],
                );
            }

            return ApiResponse::success([
                'user_id' => $user->id,
                'onboarded' => true,
                'interests' => $normalized,
                'locale' => is_string($locale)
                    ? $locale
                    : DB::table('user_profiles')->where('user_id', $user->id)->value('locale'),
            ]);
        });
    }
}
