<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AppealController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = DB::table('appeals')->where('user_id', $request->user()->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(min(max($request->integer('limit', 20), 1), 50));

        return ApiResponse::success($items->items(), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['subject_type' => ['required', 'in:reel,comment,sanction'], 'subject_id' => ['required', 'string', 'max:26'], 'reason' => ['required', 'string', 'min:20', 'max:2000']]);
        if (! $this->owns($data['subject_type'], $data['subject_id'], $request)) {
            return ApiResponse::error('NOT_FOUND', 'Appealable action not found.', 404);
        }
        $id = (string) Str::ulid();
        $inserted = DB::table('appeals')->insertOrIgnore(['id' => $id, 'user_id' => $request->user()->id, 'subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id'], 'reason' => $data['reason'], 'state' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        if (! $inserted) {
            return ApiResponse::error('CONFLICT', 'An appeal for this action already exists.', 409);
        }

        return ApiResponse::success(DB::table('appeals')->find($id), status: 201);
    }

    private function owns(string $type, string $id, Request $request): bool
    {
        if ($type === 'comment') {
            return DB::table('comments')->where('id', $id)->where('user_id', $request->user()->id)->exists();
        }
        if ($type === 'sanction') {
            return DB::table('user_sanctions')->where('id', $id)->where('user_id', $request->user()->id)->exists();
        }
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();

        return $creator && DB::table('reels')->where('id', $id)->where('creator_profile_id', $creator->id)->whereIn('state', ['rejected', 'removed'])->exists();
    }
}
