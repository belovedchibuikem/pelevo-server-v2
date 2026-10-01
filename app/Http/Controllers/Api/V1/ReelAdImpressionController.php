<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\AllocateReelAdRevenue;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ReelAdImpressionController extends Controller
{
    public function watched(string $adImpression, Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_event_id' => ['required', 'uuid'],
            'feed_session_id' => ['required', 'uuid'],
        ]);

        $result = DB::transaction(function () use ($adImpression, $request, $data): array {
            $impression = DB::table('ad_impressions')
                ->where('id', $adImpression)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();
            if (! $impression) {
                return ['error' => ['NOT_FOUND', 'Ad impression not found.', 404]];
            }
            if ($impression->client_event_id === $data['client_event_id'] && $impression->status === 'watched') {
                return ['impression' => $impression, 'idempotent' => true];
            }
            if ($impression->feed_session_id !== $data['feed_session_id']) {
                return ['error' => ['SESSION_MISMATCH', 'Ad impression session does not match.', 409]];
            }
            $platform = strtolower((string) $request->header('X-Platform', ''));
            if ($platform !== $impression->platform) {
                return ['error' => ['PLATFORM_MISMATCH', 'Ad impression platform does not match.', 409]];
            }
            $deviceId = $request->header('X-Device-Id');
            if ($impression->installation_hash && (! $deviceId || ! hash_equals($impression->installation_hash, hash('sha256', (string) $deviceId)))) {
                return ['error' => ['DEVICE_MISMATCH', 'Ad impression device does not match.', 409]];
            }
            if ($impression->status !== 'pending') {
                return ['error' => ['IMPRESSION_NOT_PENDING', 'Ad impression is no longer pending.', 409]];
            }
            if (now()->greaterThan(CarbonImmutable::parse($impression->expires_at))) {
                DB::table('ad_impressions')->where('id', $adImpression)->update([
                    'status' => 'bounced',
                    'bounced_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['error' => ['IMPRESSION_EXPIRED', 'Ad impression expired before confirmation.', 409]];
            }

            DB::table('ad_impressions')->where('id', $adImpression)->update([
                'status' => 'watched',
                'watched_at' => now(),
                'client_event_id' => $data['client_event_id'],
                'updated_at' => now(),
            ]);
            DB::afterCommit(fn () => AllocateReelAdRevenue::dispatch($adImpression));

            return [
                'impression' => DB::table('ad_impressions')->where('id', $adImpression)->first(),
                'idempotent' => false,
            ];
        }, 3);

        if (isset($result['error'])) {
            Log::warning('reels_ad.impression_rejected', [
                'ad_impression_id' => $adImpression,
                'reason' => $result['error'][0],
            ]);

            return ApiResponse::error(...$result['error']);
        }
        Log::info('reels_ad.impression_watched', [
            'ad_impression_id' => $adImpression,
            'idempotent' => $result['idempotent'],
        ]);

        return ApiResponse::success([
            'id' => $result['impression']->id,
            'status' => $result['impression']->status,
            'watched_at' => $result['impression']->watched_at,
            'idempotent' => $result['idempotent'],
        ]);
    }

    public function bounce(string $adImpression, Request $request): JsonResponse
    {
        $data = $request->validate([
            'feed_session_id' => ['required', 'uuid'],
            'reason' => ['required', 'in:load_failed,skipped,session_closed'],
        ]);

        $updated = DB::table('ad_impressions')
            ->where('id', $adImpression)
            ->where('user_id', $request->user()->id)
            ->where('feed_session_id', $data['feed_session_id'])
            ->where('status', 'pending')
            ->update([
                'status' => 'bounced',
                'bounced_at' => now(),
                'updated_at' => now(),
            ]);

        if (! $updated) {
            $exists = DB::table('ad_impressions')
                ->where('id', $adImpression)
                ->where('user_id', $request->user()->id)
                ->exists();

            return $exists
                ? ApiResponse::error('IMPRESSION_NOT_PENDING', 'Ad impression is no longer pending.', 409)
                : ApiResponse::error('NOT_FOUND', 'Ad impression not found.', 404);
        }
        Log::info('reels_ad.impression_bounced', [
            'ad_impression_id' => $adImpression,
            'reason' => $data['reason'],
        ]);

        return ApiResponse::success(['id' => $adImpression, 'status' => 'bounced']);
    }

    public function paid(string $adImpression, Request $request): JsonResponse
    {
        $data = $request->validate([
            'value_micros' => ['required', 'integer', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'precision' => ['required', 'string', 'max:32'],
        ]);

        $updated = DB::table('ad_impressions')
            ->where('id', $adImpression)
            ->where('user_id', $request->user()->id)
            ->update([
                'estimated_value_micros' => $data['value_micros'],
                'estimated_currency' => strtoupper($data['currency_code']),
                'estimated_precision' => $data['precision'],
                'updated_at' => now(),
            ]);

        return $updated
            ? ApiResponse::success(['id' => $adImpression, 'recorded' => true])
            : ApiResponse::error('NOT_FOUND', 'Ad impression not found.', 404);
    }
}
