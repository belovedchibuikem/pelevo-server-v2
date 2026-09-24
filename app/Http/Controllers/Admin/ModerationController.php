<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ReelLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ModerationController extends Controller
{
    public function comment(string $comment, Request $request): JsonResponse
    {
        return $this->act('comments', $comment, $request, ['hide', 'restore'], fn (string $action): array => ['hidden_at' => $action === 'hide' ? now() : null]);
    }

    public function reel(string $reel, Request $request): JsonResponse
    {
        $action = $request->input('action');
        if (in_array($action, ['publish', 'restore'], true)) {
            $record = DB::table('reels')->where('id', $reel)->first();
            $mediaReady = DB::table('reel_media')->where('reel_id', $reel)->where('processing_state', 'ready')->exists();
            if (! $record || $record->duration_ms === null || ! $mediaReady) {
                return ApiResponse::error('MODERATION_HOLD', 'A completed server media probe is required.', 409);
            }
            if ($record->duration_ms > ReelLimits::maxDurationMs()) {
                return ApiResponse::error('UPLOAD_TOO_LONG', ReelLimits::tooLongMessage(), 422);
            }
        }
        if ($action === 'delete') {
            return $this->deleteReel($reel, $request);
        }

        return $this->act('reels', $reel, $request, ['publish', 'restore', 'reject', 'remove', 'strike', 'escalate'], fn (string $action): array => ['state' => ['publish' => 'published', 'restore' => 'published', 'reject' => 'rejected', 'remove' => 'removed', 'strike' => 'removed', 'escalate' => 'escalated'][$action], 'published_at' => in_array($action, ['publish', 'restore'], true) ? now() : null]);
    }

    private function deleteReel(string $reel, Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:delete'], 'reason_code' => ['required', 'string', 'max:80'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'user_facing_explanation' => ['nullable', 'string', 'max:1000']]);

        return DB::transaction(function () use ($reel, $request, $data): JsonResponse {
            $record = DB::table('reels')->where('id', $reel)->lockForUpdate()->first();
            if (! $record) {
                return ApiResponse::error('NOT_FOUND', 'Moderation subject not found.', 404);
            }
            DB::table('comments')->where('commentable_type', 'reel')->where('commentable_id', $reel)->whereNotNull('parent_id')->delete();
            DB::table('comments')->where('commentable_type', 'reel')->where('commentable_id', $reel)->delete();
            DB::table('reels')->where('id', $reel)->delete();
            Cache::forget('reel:'.$reel);
            $actionId = (string) Str::ulid();
            $type = 'App\\Models\\Reel';
            DB::table('moderation_actions')->insert(['id' => $actionId, 'admin_id' => auth('admin')->id(), 'subject_type' => $type, 'subject_id' => $reel, 'action' => 'delete', 'reason_code' => $data['reason_code'], 'reason' => $data['reason'], 'evidence' => json_encode(['before' => $record, 'user_facing_explanation' => $data['user_facing_explanation'] ?? null], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => auth('admin')->id(), 'action' => 'reels.delete', 'subject_type' => $type, 'subject_id' => $reel, 'reason' => $data['reason'], 'before' => json_encode($record), 'after' => json_encode(['deleted' => true]), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['state' => ['deleted' => true], 'audit_reference' => $actionId]);
        });
    }

    private function act(string $table, string $id, Request $request, array $allowed, \Closure $changes): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:'.implode(',', $allowed)], 'reason_code' => ['required', 'string', 'max:80'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'user_facing_explanation' => ['nullable', 'string', 'max:1000']]);

        return DB::transaction(function () use ($table, $id, $request, $data, $changes): JsonResponse {
            $record = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if (! $record) {
                return ApiResponse::error('NOT_FOUND', 'Moderation subject not found.', 404);
            } $updates = [...$changes($data['action']), 'updated_at' => now()];
            DB::table($table)->where('id', $id)->update($updates);
            if ($table === 'reels') {
                Cache::forget('reel:'.$id);
                DB::table('reel_processing_events')->insert(['id' => (string) Str::ulid(), 'reel_id' => $id, 'state' => $updates['state'], 'details' => json_encode(['reason_code' => $data['reason_code']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
                if ($data['action'] === 'strike') {
                    $userId = DB::table('reels')->join('creator_profiles', 'creator_profiles.id', '=', 'reels.creator_profile_id')->where('reels.id', $id)->value('creator_profiles.user_id');
                    DB::table('user_sanctions')->insert(['id' => (string) Str::ulid(), 'user_id' => $userId, 'admin_id' => auth('admin')->id(), 'type' => 'content_restriction', 'state' => 'active', 'reason' => $data['reason'], 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $actionId = (string) Str::ulid();
            $type = $table === 'comments' ? 'App\\Models\\Comment' : 'App\\Models\\Reel';
            DB::table('moderation_actions')->insert(['id' => $actionId, 'admin_id' => auth('admin')->id(), 'subject_type' => $type, 'subject_id' => $id, 'action' => $data['action'], 'reason_code' => $data['reason_code'], 'reason' => $data['reason'], 'evidence' => json_encode(['before' => $record, 'user_facing_explanation' => $data['user_facing_explanation'] ?? null], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => auth('admin')->id(), 'action' => $table.'.'.$data['action'], 'subject_type' => $type, 'subject_id' => $id, 'reason' => $data['reason'], 'before' => json_encode($record), 'after' => json_encode($updates), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['state' => $updates, 'audit_reference' => $actionId]);
        });
    }
}
