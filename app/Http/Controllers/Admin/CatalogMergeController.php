<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\MergeDuplicateShow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CatalogMergeController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['survivor_show_id' => ['required', 'different:duplicate_show_id', 'exists:shows,id'], 'duplicate_show_id' => ['required', 'exists:shows,id']]);

        return ApiResponse::success($this->buildPreview($data['survivor_show_id'], $data['duplicate_show_id']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['survivor_show_id' => ['required', 'different:duplicate_show_id', 'exists:shows,id'], 'duplicate_show_id' => ['required', 'exists:shows,id'], 'confirmation' => ['required', 'string'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:191']]);
        if ($data['confirmation'] !== 'MERGE '.$data['duplicate_show_id']) {
            return ApiResponse::error('VALIDATION', 'Typed merge confirmation does not match.', 422);
        }
        $preview = $this->buildPreview($data['survivor_show_id'], $data['duplicate_show_id']);
        if ($preview['conflicts'] !== []) {
            return ApiResponse::error('MERGE_CONFLICT', 'Resolve verified-claim or episode identity conflicts first.', 409, ['conflicts' => $preview['conflicts']]);
        }
        $existing = DB::table('catalog_merges')->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return ApiResponse::success($existing);
        }
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $data, $preview, $request): void {
            DB::table('catalog_merges')->insert(['id' => $id, 'survivor_show_id' => $data['survivor_show_id'], 'duplicate_show_id' => $data['duplicate_show_id'], 'admin_id' => auth('admin')->id(), 'state' => 'queued', 'idempotency_key' => $data['idempotency_key'], 'preview' => json_encode($preview), 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => auth('admin')->id(), 'action' => 'catalog.merge_queued', 'subject_type' => 'App\\Models\\CatalogMerge', 'subject_id' => $id, 'reason' => $data['reason'], 'after' => json_encode($preview), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        });
        MergeDuplicateShow::dispatch($id)->afterCommit();

        return ApiResponse::success(['merge_id' => $id, 'state' => 'queued', 'preview' => $preview], status: 202);
    }

    public function show(string $merge): JsonResponse
    {
        $row = DB::table('catalog_merges')->where('id', $merge)->first();

        return $row ? ApiResponse::success($row) : ApiResponse::error('NOT_FOUND', 'Catalog merge not found.', 404);
    }

    private function buildPreview(string $survivor, string $duplicate): array
    {
        $duplicateGuids = DB::table('episodes')->where('show_id', $duplicate)->pluck('guid');
        $episodeConflicts = DB::table('episodes')->where('show_id', $survivor)->whereIn('guid', $duplicateGuids)->pluck('guid')->all();
        $verified = DB::table('verified_show_claims')->whereIn('show_id', [$survivor, $duplicate])->count();
        $conflicts = [];
        if ($verified > 1) {
            $conflicts[] = ['type' => 'verified_claims', 'message' => 'Both shows have verified owners.'];
        }
        if ($episodeConflicts !== []) {
            $conflicts[] = ['type' => 'episode_identity', 'guids' => $episodeConflicts];
        }

        return ['survivor' => DB::table('shows')->find($survivor), 'duplicate' => DB::table('shows')->find($duplicate), 'counts' => ['episodes' => DB::table('episodes')->where('show_id', $duplicate)->count(), 'follows' => DB::table('follows')->where('show_id', $duplicate)->count(), 'ratings' => DB::table('show_ratings')->where('show_id', $duplicate)->count(), 'reviews' => DB::table('show_reviews')->where('show_id', $duplicate)->count(), 'reels' => DB::table('reels')->where('show_id', $duplicate)->count(), 'claims' => DB::table('show_claims')->where('show_id', $duplicate)->count()], 'conflicts' => $conflicts];
    }
}
