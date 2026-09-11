<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SearchHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('search_history')->where('user_id', $request->user()->id)->select('id', 'query', 'searched_at')->orderByDesc('searched_at')->orderByDesc('id')->limit(20)->get()->map(fn (object $row): array => [
            'id' => $row->id,
            'query' => $row->query,
            'searched_at' => $row->searched_at,
        ])->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'min:2', 'max:100']]);
        $query = trim($data['query']);
        $hash = hash('sha256', Str::lower($query));
        $existing = DB::table('search_history')->where('user_id', $request->user()->id)->where('query_hash', $hash)->first();
        if ($existing) {
            DB::table('search_history')->where('id', $existing->id)->update(['query' => $query, 'searched_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('search_history')->insert(['id' => (string) Str::ulid(), 'user_id' => $request->user()->id, 'query_hash' => $hash, 'query' => $query, 'searched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return ApiResponse::success(['saved' => true], status: 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        DB::table('search_history')->where('user_id', $request->user()->id)->delete();

        return ApiResponse::success(['cleared' => true]);
    }
}
