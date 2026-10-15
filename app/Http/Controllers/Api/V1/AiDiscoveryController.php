<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ai\RecommendListening;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiDiscoveryController extends Controller
{
    public function ask(Request $request, RecommendListening $recommend): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:280'],
        ]);

        return ApiResponse::success($recommend->ask($request->user()->id, $data['message']));
    }

    public function lineup(Request $request, RecommendListening $recommend): JsonResponse
    {
        return ApiResponse::success($recommend->lineup($request->user()->id));
    }
}
