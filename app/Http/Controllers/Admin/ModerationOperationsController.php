<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ModerationOperationsController extends Controller
{
    public function reel(string $reel): JsonResponse
    {
        $row = DB::table('reels')->join('creator_profiles', 'creator_profiles.id', '=', 'reels.creator_profile_id')->leftJoin('reel_media', 'reel_media.reel_id', '=', 'reels.id')->leftJoin('media_uploads', 'media_uploads.id', '=', 'reel_media.media_upload_id')->where('reels.id', $reel)->select('reels.*', 'creator_profiles.display_name', 'reel_media.mime', 'reel_media.processing_state', 'reel_media.width', 'reel_media.height', 'reel_media.thumbnail_path', 'media_uploads.probe')->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Reel not found.', 404);
        }

        return ApiResponse::success(['reel' => $row, 'engagement' => DB::table('reel_engagements')->where('reel_id', $reel)->selectRaw('SUM(CASE WHEN liked THEN 1 ELSE 0 END) likes, SUM(CASE WHEN saved THEN 1 ELSE 0 END) saves, SUM(CASE WHEN not_interested THEN 1 ELSE 0 END) not_interested')->first(), 'qualified_views' => DB::table('reel_view_credits')->where('reel_id', $reel)->count(), 'reports' => DB::table('content_reports')->where('reportable_type', 'reel')->where('reportable_id', $reel)->get(), 'actions' => DB::table('moderation_actions')->where('subject_type', 'App\\Models\\Reel')->where('subject_id', $reel)->orderBy('created_at')->get(), 'processing' => DB::table('reel_processing_events')->where('reel_id', $reel)->orderBy('created_at')->get(), 'appeals' => DB::table('appeals')->where('subject_type', 'reel')->where('subject_id', $reel)->get()]);
    }

    public function comment(string $comment): JsonResponse
    {
        $row = DB::table('comments')->where('id', $comment)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Comment not found.', 404);
        }
        $thread = DB::table('comments')->where('commentable_type', $row->commentable_type)->where('commentable_id', $row->commentable_id)->where(fn ($q) => $q->where('id', $row->id)->orWhere('parent_id', $row->id)->orWhere('id', $row->parent_id))->orderBy('created_at')->get();

        return ApiResponse::success(['comment' => $row, 'thread' => $thread, 'reports' => DB::table('content_reports')->where('reportable_type', 'comment')->where('reportable_id', $comment)->get(), 'actions' => DB::table('moderation_actions')->where('subject_type', 'App\\Models\\Comment')->where('subject_id', $comment)->get()]);
    }

    public function report(string $report): JsonResponse
    {
        $row = DB::table('content_reports')->where('id', $report)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Report not found.', 404);
        }
        $table = ['comment' => 'comments', 'reel' => 'reels', 'episode' => 'episodes'][$row->reportable_type] ?? null;

        return ApiResponse::success(['report' => $row, 'target' => $table ? DB::table($table)->where('id', $row->reportable_id)->first() : null, 'reporter_history' => DB::table('content_reports')->where('reporter_id', $row->reporter_id)->latest()->limit(20)->get()]);
    }

    public function resolveReport(string $report, Request $request): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'in:dismissed,actioned,escalated'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($report, $request, $data): JsonResponse {
            $row = DB::table('content_reports')->where('id', $report)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Report not found.', 404);
            }
            if ($row->state !== 'open') {
                return ApiResponse::error('INVALID_STATE', 'Report is already resolved.', 409);
            }
            DB::table('content_reports')->where('id', $report)->update(['state' => $data['resolution'] === 'escalated' ? 'escalated' : 'resolved', 'resolved_by' => auth('admin')->id(), 'resolution' => $data['resolution'], 'resolution_reason' => $data['reason'], 'resolved_at' => now(), 'updated_at' => now()]);
            $audit = $this->audit($request, 'report.'.$data['resolution'], 'App\\Models\\ContentReport', $report, $data['reason'], ['state' => $row->state], ['resolution' => $data['resolution']]);

            return ApiResponse::success(['report_id' => $report, 'state' => $data['resolution'], 'audit_reference' => $audit]);
        });
    }

    public function sanction(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'type' => ['required', 'in:content_restriction,suspension'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $id = (string) Str::ulid();
        DB::table('user_sanctions')->insert(['id' => $id, 'user_id' => $data['user_id'], 'admin_id' => auth('admin')->id(), 'type' => $data['type'], 'state' => 'active', 'reason' => $data['reason'], 'expires_at' => $data['expires_at'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $audit = $this->audit($request, 'user.sanctioned', 'App\\Models\\User', $data['user_id'], $data['reason'], [], ['sanction_id' => $id, 'type' => $data['type']]);

        return ApiResponse::success(['sanction_id' => $id, 'audit_reference' => $audit], status: 201);
    }

    public function appeal(string $appeal, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:granted,denied,escalated'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($appeal, $request, $data): JsonResponse {
            $row = DB::table('appeals')->where('id', $appeal)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Appeal not found.', 404);
            }
            if ($row->state !== 'open') {
                return ApiResponse::error('INVALID_STATE', 'Appeal has already been decided.', 409);
            }
            $state = $data['decision'] === 'escalated' ? 'escalated' : $data['decision'];
            if ($data['decision'] === 'granted') {
                if ($row->subject_type === 'reel') {
                    DB::table('reels')->where('id', $row->subject_id)->whereIn('state', ['rejected', 'removed'])->update(['state' => 'pending_review', 'updated_at' => now()]);
                }
                if ($row->subject_type === 'comment') {
                    DB::table('comments')->where('id', $row->subject_id)->update(['hidden_at' => null, 'updated_at' => now()]);
                }
                if ($row->subject_type === 'sanction') {
                    DB::table('user_sanctions')->where('id', $row->subject_id)->update(['state' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('appeals')->where('id', $appeal)->update(['state' => $state, 'decided_by' => auth('admin')->id(), 'decision_reason' => $data['reason'], 'decided_at' => now(), 'updated_at' => now()]);
            Cache::forget('reel:'.$row->subject_id);
            $audit = $this->audit($request, 'appeal.'.$data['decision'], 'App\\Models\\Appeal', $appeal, $data['reason'], ['state' => $row->state], ['state' => $state]);

            return ApiResponse::success(['appeal_id' => $appeal, 'state' => $state, 'audit_reference' => $audit]);
        });
    }

    public function live(string $session): JsonResponse
    {
        $row = DB::table('live_sessions')->where('id', $session)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Live session not found.', 404);
        }

        return ApiResponse::success(['session' => $row, 'health' => DB::table('live_health_snapshots')->where('live_session_id', $session)->latest('recorded_at')->limit(100)->get(), 'events' => DB::table('live_events')->where('live_session_id', $session)->orderBy('created_at')->get(), 'takedowns' => DB::table('live_takedowns')->where('live_session_id', $session)->latest()->get()]);
    }

    public function takedown(string $session, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($session, $request, $data): JsonResponse {
            $row = DB::table('live_sessions')->where('id', $session)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Live session not found.', 404);
            }
            if (! in_array($row->state, ['scheduled', 'live'], true)) {
                return ApiResponse::error('INVALID_STATE', 'Live session is not active.', 409);
            }
            $id = (string) Str::ulid();
            DB::table('live_sessions')->where('id', $session)->update(['state' => 'removed', 'ended_at' => now(), 'updated_at' => now()]);
            DB::table('live_takedowns')->insert(['id' => $id, 'live_session_id' => $session, 'admin_id' => auth('admin')->id(), 'reason' => $data['reason'], 'previous_state' => $row->state, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('live_events')->insert(['id' => (string) Str::ulid(), 'live_session_id' => $session, 'type' => 'removed', 'payload' => json_encode(['takedown_id' => $id]), 'created_at' => now()]);
            $audit = $this->audit($request, 'live.removed', 'App\\Models\\LiveSession', $session, $data['reason'], ['state' => $row->state], ['state' => 'removed']);

            return ApiResponse::success(['takedown_id' => $id, 'audit_reference' => $audit]);
        });
    }

    private function audit(Request $request, string $action, string $type, string $id, string $reason, array $before, array $after): string
    {
        $audit = (string) Str::ulid();
        DB::table('audit_logs')->insert(['id' => $audit, 'admin_id' => auth('admin')->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'reason' => $reason, 'before' => json_encode($before), 'after' => json_encode($after), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return $audit;
    }
}
