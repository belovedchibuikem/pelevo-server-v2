<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAiJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdvancedController extends Controller
{
    private const TYPES = ['playlist', 'collection', 'note', 'queue', 'preference'];

    public function syncPush(Request $request): JsonResponse
    {
        if (! config('features.sync', true)) {
            return ApiResponse::error('FORBIDDEN', 'Device sync is disabled.', 403);
        }
        $data = $request->validate(['changes' => ['required', 'array', 'max:100'], 'changes.*.resource_type' => ['required', 'in:'.implode(',', self::TYPES)], 'changes.*.resource_id' => ['required', 'string'], 'changes.*.operation' => ['required', 'in:create,update,delete'], 'changes.*.version' => ['required', 'integer', 'min:1'], 'changes.*.payload' => ['required', 'array']]);
        $device = DB::table('devices')->where('user_id', $request->user()->id)->where('device_identifier', $request->header('X-Device-Id'))->whereNull('revoked_at')->first();
        if (! $device) {
            return ApiResponse::error('FORBIDDEN', 'Registered device required.', 403);
        }
        $accepted = 0;
        $conflicts = [];
        foreach ($data['changes'] as $change) {
            DB::transaction(function () use ($request, $device, $change, &$accepted, &$conflicts): void {
                $state = DB::table('sync_resource_states')->where('user_id', $request->user()->id)->where('resource_type', $change['resource_type'])->where('resource_id', $change['resource_id'])->lockForUpdate()->first();
                if ($state && (int) $change['version'] !== (int) $state->version + 1) {
                    $id = (string) Str::ulid();
                    DB::table('sync_conflicts')->insert(['id' => $id, 'user_id' => $request->user()->id, 'sync_resource_state_id' => $state->id, 'device_id' => $device->id, 'client_version' => $change['version'], 'client_payload' => json_encode($change['payload'], JSON_THROW_ON_ERROR), 'server_payload' => $state->payload, 'state' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                    $conflicts[] = $id;

                    return;
                }
                $values = ['version' => $change['version'], 'payload' => json_encode($change['payload'], JSON_THROW_ON_ERROR), 'device_id' => $device->id, 'deleted_at' => $change['operation'] === 'delete' ? now() : null, 'updated_at' => now()];
                if ($state) {
                    DB::table('sync_resource_states')->where('id', $state->id)->update($values);
                } else {
                    DB::table('sync_resource_states')->insert([...$values, 'id' => (string) Str::ulid(), 'user_id' => $request->user()->id, 'resource_type' => $change['resource_type'], 'resource_id' => $change['resource_id'], 'created_at' => now()]);
                }
                DB::table('sync_changes')->insert(['id' => (string) Str::ulid(), 'user_id' => $request->user()->id, 'device_id' => $device->id, ...$change, 'payload' => json_encode($change['payload'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                $accepted++;
            }, 3);
        }

        return ApiResponse::success(['accepted' => $accepted, 'conflicts' => $conflicts], status: $conflicts ? 207 : 200);
    }

    public function syncPull(Request $request): JsonResponse
    {
        $items = DB::table('sync_changes')->where('user_id', $request->user()->id)->when($request->string('cursor')->isNotEmpty(), fn ($q) => $q->where('id', '>', $request->string('cursor')))->orderBy('id')->limit(100)->get(['id', 'resource_type', 'resource_id', 'operation', 'version']);

        return ApiResponse::success($items, ['cursor' => $items->last()?->id, 'has_more' => $items->count() === 100]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('sync_conflicts')->join('sync_resource_states', 'sync_resource_states.id', '=', 'sync_conflicts.sync_resource_state_id')->where('sync_conflicts.user_id', $request->user()->id)->where('sync_conflicts.state', 'open')->orderBy('sync_conflicts.created_at')->get(['sync_conflicts.id', 'sync_conflicts.created_at', 'sync_conflicts.state', 'sync_resource_states.resource_type']));
    }

    public function resolveConflict(string $conflict, Request $request): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'in:server,client'], 'payload' => ['required_if:resolution,client', 'array']]);

        return DB::transaction(function () use ($conflict, $request, $data): JsonResponse {
            $row = DB::table('sync_conflicts')->where('id', $conflict)->where('user_id', $request->user()->id)->where('state', 'open')->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Sync conflict not found.', 404);
            }
            if ($data['resolution'] === 'client') {
                DB::table('sync_resource_states')->where('id', $row->sync_resource_state_id)->update(['version' => DB::raw('version + 1'), 'payload' => json_encode($data['payload'], JSON_THROW_ON_ERROR), 'device_id' => $row->device_id, 'updated_at' => now()]);
            }
            DB::table('sync_conflicts')->where('id', $row->id)->update(['state' => 'resolved', 'resolution' => $data['resolution'], 'resolved_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['resolved' => true]);
        }, 3);
    }

    public function aiJob(Request $request): JsonResponse
    {
        if (! config('features.ai', false)) {
            return ApiResponse::error('FORBIDDEN', 'AI tools are disabled.', 403);
        }
        $data = $request->validate(['type' => ['required', 'in:summary,chapters,transcript'], 'input' => ['required', 'array']]);
        $daily = DB::table('ai_jobs')->where('user_id', $request->user()->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($daily >= config('ai.daily_job_cap', 10)) {
            return ApiResponse::error('QUOTA_EXCEEDED', 'Daily AI quota reached.', 429);
        }
        $prompt = DB::table('prompt_versions')->where('type', $data['type'])->where('state', 'active')->latest('version')->first();
        if (! $prompt) {
            return ApiResponse::error('SERVICE_DEGRADED', 'No active prompt version is configured.', 503);
        }
        $id = (string) Str::ulid();
        DB::table('ai_jobs')->insert(['id' => $id, 'user_id' => $request->user()->id, 'type' => $data['type'], 'state' => 'queued', 'input' => json_encode([...$data['input'], '_prompt_version' => $prompt->version], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        ProcessAiJob::dispatch($id);

        return ApiResponse::success(['id' => $id, 'type' => $data['type'], 'state' => 'queued'], status: 202);
    }

    public function aiShow(string $job, Request $request): JsonResponse
    {
        $row = DB::table('ai_jobs')->where('id', $job)->where('user_id', $request->user()->id)->select('id', 'type', 'state', 'output', 'created_at', 'updated_at')->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'AI job not found.', 404);
        }
        $decoded = is_string($row->output) ? json_decode($row->output, true) : $row->output;
        $text = is_array($decoded) && is_string($decoded['text'] ?? null) ? $decoded['text'] : null;

        return ApiResponse::success([
            'id' => $row->id,
            'type' => $row->type,
            'state' => $row->state,
            'output' => $text === null ? null : ['text' => $text],
        ]);
    }
}
