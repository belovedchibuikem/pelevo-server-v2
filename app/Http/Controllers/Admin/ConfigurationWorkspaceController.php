<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationVersion;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ConfigurationWorkspaceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Configuration', ['versions' => ConfigurationVersion::latest('version')->limit(50)->get(), 'freshAt' => now()->toIso8601String()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['base_version' => ['required', 'integer'], 'payload' => ['required', 'array:flags,limits,money,tabs'], 'payload.flags' => ['required', 'array'], 'payload.limits' => ['required', 'array'], 'payload.money' => ['required', 'array'], 'payload.tabs' => ['required', 'array', 'min:1', 'max:10'], 'payload.tabs.*' => ['required', 'in:home,search,library,earn,reels', 'distinct'], 'effective_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after_or_equal:now'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($data, $request): JsonResponse {
            $current = ConfigurationVersion::latest('version')->lockForUpdate()->firstOrFail();
            if ((int) $current->version !== $data['base_version']) {
                return ApiResponse::error('CONFLICT', 'A newer configuration exists. Reload and compare your proposed changes before publishing.', 409);
            }
            $rules = [];
            foreach (['flags', 'limits', 'money'] as $group) {
                $rules[$group] = ['required', 'array:'.implode(',', array_keys($current->payload[$group]))];
                foreach ($current->payload[$group] as $key => $value) {
                    $rules[$group.'.'.$key] = $group === 'flags' ? ['required', 'boolean'] : ['required', is_int($value) ? 'integer' : 'numeric', is_int($value) ? 'min:1' : 'gt:0', 'max:100000000'];
                }
            }
            Validator::make($data['payload'], $rules)->validate();
            $version = ConfigurationVersion::create(['version' => $current->version + 1, 'payload' => $data['payload'], 'created_by' => $request->user('admin')->id, 'reason' => $data['reason'], 'effective_at' => $data['effective_at']]);
            $audit = app(WorkspaceToolsController::class)->audit($request->user('admin')->id, 'configuration.published', $version->id, $data['reason'], ['previous_version' => $current->version, 'version' => $version->version, 'effective_at' => $data['effective_at']]);

            return ApiResponse::success(['version' => $version->version, 'audit_reference' => $audit], status: 201);
        }, 3);
    }
}
