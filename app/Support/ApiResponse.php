<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data, 'meta' => $meta ?: (object) [], 'error' => null, 'request_id' => request()->attributes->get('request_id')], $status);
    }

    public static function error(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        return response()->json(['ok' => false, 'data' => null, 'error' => ['code' => $code, 'message' => $message, 'fields' => $fields ?: (object) []], 'request_id' => request()->attributes->get('request_id')], $status);
    }
}
