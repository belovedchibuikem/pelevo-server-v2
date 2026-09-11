<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['reportable_type' => ['required', 'in:comment,reel,episode'], 'reportable_id' => ['required', 'string', 'max:26'], 'reason' => ['required', 'in:spam,abuse,harassment,misinformation,copyright,other'], 'details' => ['nullable', 'string', 'max:1000']]);
        $table = ['comment' => 'comments', 'reel' => 'reels', 'episode' => 'episodes'][$data['reportable_type']];
        abort_unless(DB::table($table)->where('id', $data['reportable_id'])->exists(), 404);
        $id = (string) Str::ulid();
        $inserted = DB::table('content_reports')->insertOrIgnore(['id' => $id, 'reporter_id' => $request->user()->id, ...$data, 'state' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        if (! $inserted) {
            return ApiResponse::error('CONFLICT', 'You already reported this content.', 409);
        }

        return ApiResponse::success(['id' => $id, 'state' => 'open'], status: 201);
    }
}
