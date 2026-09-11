<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class PublicLiveController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(DB::table('live_sessions')->whereIn('state', ['scheduled', 'live'])->orderByRaw("state = 'live' desc")->orderBy('scheduled_at')->limit(50)->get());
    }

    public function show(string $session): JsonResponse
    {
        $row = DB::table('live_sessions')->where('id', $session)->whereIn('state', ['scheduled', 'live', 'ended'])->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Live session not found.', 404);
        }
        $row->events = DB::table('live_events')->where('live_session_id', $session)->orderByDesc('created_at')->limit(20)->get();

        return ApiResponse::success($row);
    }
}
