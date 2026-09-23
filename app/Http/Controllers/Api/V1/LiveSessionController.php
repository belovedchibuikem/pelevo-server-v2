<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\PelevoNotice;
use App\Models\CreatorProfile;
use App\Services\InAppNotificationDelivery;
use App\Services\MailPreference;
use App\Services\MuxMedia;
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
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'show_id' => ['nullable', 'exists:shows,id'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'notify_followers' => ['sometimes', 'boolean'],
            'comments_enabled' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['show_id']) && ! DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('show_claims.creator_profile_id', $creator->id)->where('verified_show_claims.show_id', $data['show_id'])->exists()) {
            return ApiResponse::error('NOT_FOUND', 'Claimed show not found.', 404);
        }
        $mux = app(MuxMedia::class)->createLiveStream($data['title']) ?? [];
        $id = (string) Str::ulid();
        DB::table('live_sessions')->insert([
            'id' => $id,
            'creator_profile_id' => $creator->id,
            'title' => $data['title'],
            'show_id' => $data['show_id'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'state' => 'scheduled',
            'mux_live_stream_id' => $mux['live_stream_id'] ?? null,
            'stream_key' => $mux['stream_key'] ?? null,
            'ingest_url' => $mux['ingest_url'] ?? null,
            'playback_url' => $mux['playback_url'] ?? null,
            'notify_followers' => $data['notify_followers'] ?? true,
            'comments_enabled' => $data['comments_enabled'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ApiResponse::success($this->present(DB::table('live_sessions')->find($id), creator: true), status: 201);
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
        if ($data['state'] === 'live' && ($row->notify_followers ?? true)) {
            $followers = collect();
            if ($row->show_id) {
                $followers = $followers->merge(DB::table('follows')->where('show_id', $row->show_id)->where('notifications_enabled', true)->pluck('user_id'));
            }
            $followers = $followers->merge(DB::table('creator_followers')->where('creator_profile_id', $row->creator_profile_id)->pluck('user_id'))->unique()->filter();
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

        return ApiResponse::success($this->present(DB::table('live_sessions')->find($session), creator: true));
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

    private function present(?object $row, bool $creator = false): ?array
    {
        if (! $row) {
            return null;
        }
        $payload = [
            'id' => $row->id,
            'title' => $row->title,
            'show_id' => $row->show_id,
            'state' => $row->state,
            'scheduled_at' => $row->scheduled_at,
            'started_at' => $row->started_at,
            'ended_at' => $row->ended_at,
            'viewer_count' => (int) $row->viewer_count,
            'playback_url' => $row->playback_url ?? null,
            'comments_enabled' => (bool) ($row->comments_enabled ?? true),
            'notify_followers' => (bool) ($row->notify_followers ?? true),
            'mux_ready' => is_string($row->playback_url ?? null) && $row->playback_url !== '',
        ];
        if ($creator) {
            $payload['ingest_url'] = $row->ingest_url ?? null;
            $payload['stream_key'] = $row->stream_key ?? null;
            $payload['mux_live_stream_id'] = $row->mux_live_stream_id ?? null;
            if (! $payload['mux_ready']) {
                $payload['mux_message'] = 'Mux live credentials are missing or the live stream could not be created. Add MUX_TOKEN_ID and MUX_TOKEN_SECRET, then try again.';
            }
        }

        return $payload;
    }
}
