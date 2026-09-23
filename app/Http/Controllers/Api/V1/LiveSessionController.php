<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\PelevoNotice;
use App\Models\CreatorProfile;
use App\Services\InAppNotificationDelivery;
use App\Services\MailPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('live_sessions')->whereIn('state', ['scheduled', 'live'])->orderByRaw("state = 'live' desc")->orderBy('scheduled_at')->limit(min($request->integer('limit', 20), 50))->get();

        return ApiResponse::success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        if (! $creator || ! DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('show_claims.creator_profile_id', $creator->id)->exists()) {
            return ApiResponse::error('CLAIM_REQUIRED', 'Creator access is required.', 403);
        }
        $data = $request->validate(['title' => ['required', 'string', 'max:191'], 'show_id' => ['nullable', 'exists:shows,id'], 'scheduled_at' => ['nullable', 'date', 'after:now']]);
        if (isset($data['show_id']) && ! DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('show_claims.creator_profile_id', $creator->id)->where('verified_show_claims.show_id', $data['show_id'])->exists()) {
            return ApiResponse::error('NOT_FOUND', 'Claimed show not found.', 404);
        }
        $id = (string) Str::ulid();
        DB::table('live_sessions')->insert(['id' => $id, 'creator_profile_id' => $creator->id, ...$data, 'state' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->present(DB::table('live_sessions')->find($id)), status: 201);
    }

    public function transition(string $session, Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'in:live,ended,cancelled']]);
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        $row = $creator ? DB::table('live_sessions')->where('id', $session)->where('creator_profile_id', $creator->id)->first() : null;
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Live session not found.', 404);
        }
        $allowed = ['scheduled' => ['live', 'cancelled'], 'live' => ['ended']];
        if (! in_array($data['state'], $allowed[$row->state] ?? [], true)) {
            return ApiResponse::error('INVALID_STATE', 'Invalid live session transition.', 409);
        }
        DB::transaction(function () use ($session, $data, $row): void {
            DB::table('live_sessions')->where('id', $session)->update(['state' => $data['state'], 'started_at' => $data['state'] === 'live' ? now() : $row->started_at, 'ended_at' => in_array($data['state'], ['ended', 'cancelled'], true) ? now() : $row->ended_at, 'updated_at' => now()]);
            DB::table('live_events')->insert(['id' => (string) Str::ulid(), 'live_session_id' => $session, 'type' => $data['state'], 'payload' => json_encode(['previous_state' => $row->state], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });
        if ($data['state'] === 'live' && $row->show_id) {
            $followers = DB::table('follows')->where('show_id', $row->show_id)->where('notifications_enabled', true)->pluck('user_id');
            $alertFooter = 'You received this because Email Notifications is on in Pelevo. Turn it off in Settings to stop these emails.';
            foreach ($followers as $userId) {
                app(InAppNotificationDelivery::class)->deliver((string) $userId, [
                    'type' => 'live',
                    'key' => 'live:'.$session,
                    'title' => 'Live now: '.$row->title,
                    'body' => 'A followed show started a live session.',
                    'data' => ['live_session_id' => $session, 'show_id' => $row->show_id],
                ]);
                app(MailPreference::class)->queueAlert((string) $userId, new PelevoNotice(
                    subjectLine: 'Live now: '.$row->title,
                    eyebrow: 'Live',
                    heading: $row->title.' is live',
                    intro: 'A show you follow just went live on Pelevo.',
                    actionLabel: 'Open Pelevo',
                    actionUrl: config('app.url'),
                    footerNote: $alertFooter,
                ));
            }
        }

        return ApiResponse::success($this->present(DB::table('live_sessions')->find($session)));
    }

    public function health(string $session, Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        $row = $creator ? DB::table('live_sessions')->where('id', $session)->where('creator_profile_id', $creator->id)->where('state', 'live')->first() : null;
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Active live session not found.', 404);
        }
        $data = $request->validate(['state' => ['required', 'in:healthy,degraded,offline'], 'bitrate_kbps' => ['nullable', 'integer', 'between:0,100000'], 'latency_ms' => ['nullable', 'integer', 'between:0,60000'], 'dropped_frames' => ['nullable', 'integer', 'between:0,100000000'], 'metrics' => ['nullable', 'array']]);
        $id = (string) Str::ulid();
        DB::table('live_health_snapshots')->insert(['id' => $id, 'live_session_id' => $session, ...$data, 'dropped_frames' => $data['dropped_frames'] ?? 0, 'metrics' => isset($data['metrics']) ? json_encode($data['metrics']) : null, 'recorded_at' => now()]);

        return ApiResponse::success(['snapshot_id' => $id, 'state' => $data['state']], status: 201);
    }

    private function present(?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        return ['id' => $row->id, 'title' => $row->title, 'show_id' => $row->show_id, 'state' => $row->state, 'scheduled_at' => $row->scheduled_at, 'started_at' => $row->started_at, 'ended_at' => $row->ended_at, 'viewer_count' => (int) $row->viewer_count];
    }
}
