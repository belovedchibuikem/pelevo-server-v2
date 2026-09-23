<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IntegrationSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class IntegrationSettingsController extends Controller
{
    public function __construct(private readonly IntegrationSettings $integrations) {}

    public function page(): Response
    {
        return Inertia::render('Admin/Integrations', [
            'integrations' => $this->integrations->present(),
            'freshAt' => now()->toIso8601String(),
            'adminEmail' => (string) (auth('admin')->user()?->email ?? ''),
        ]);
    }

    public function update(string $provider, Request $request): JsonResponse
    {
        abort_unless(isset($this->integrations->catalog()[$provider]), 404);
        $data = $request->validate(['values' => ['required', 'array'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $this->integrations->save($provider, $data['values'], (string) $request->user('admin')->id, $data['reason']);
        DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => $request->user('admin')->id, 'action' => 'integration.updated', 'subject_type' => 'integration_settings', 'subject_id' => $provider, 'reason' => $data['reason'], 'after' => json_encode(['provider' => $provider, 'fields' => array_keys($data['values'])]), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['provider' => $provider, 'integrations' => $this->integrations->present()]);
    }

    public function test(string $provider, Request $request): JsonResponse
    {
        abort_unless(isset($this->integrations->catalog()[$provider]), 404);
        $data = $request->validate(['to' => ['nullable', 'email', 'max:255']]);
        $result = $this->integrations->test($provider, $data);
        DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => $request->user('admin')->id, 'action' => 'integration.tested', 'subject_type' => 'integration_settings', 'subject_id' => $provider, 'reason' => 'Connectivity probe.', 'after' => json_encode(['ok' => $result['ok'], 'to' => $data['to'] ?? null]), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($result);
    }
}
