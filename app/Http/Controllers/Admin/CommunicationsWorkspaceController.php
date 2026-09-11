<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CommunicationsWorkspaceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Communications', [
            'enabled' => (bool) config('features.notifications'),
            'templates' => DB::table('notification_templates')->where('active', true)->orderBy('key')->latest('version')->get(['id', 'key', 'version', 'title', 'body']),
            'broadcasts' => DB::table('notification_broadcasts')->latest()->limit(50)->get(['id', 'state', 'scheduled_at', 'notification_template_id', 'created_at']),
            'deliveryStates' => DB::table('notification_deliveries')->selectRaw('state, count(*) as total')->groupBy('state')->get(),
            'freshAt' => now()->toIso8601String(),
        ]);
    }

    public function audience(Request $request): JsonResponse
    {
        $data = $request->validate(['country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/']]);
        $query = DB::table('users')->where('status', 'active');
        if (! empty($data['country'])) {
            $query->where('country_code', $data['country']);
        }

        return ApiResponse::success(['recipients' => $query->count(), 'country' => $data['country'] ?? null, 'captured_at' => now()->toIso8601String()]);
    }

    public function cancel(Request $request, string $broadcast): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return DB::transaction(function () use ($request, $broadcast, $data): JsonResponse {
            $updated = DB::table('notification_broadcasts')->where('id', $broadcast)->where('state', 'scheduled')->update(['state' => 'cancelled', 'updated_at' => now()]);
            if (! $updated) {
                return ApiResponse::error('CONFLICT', 'Only a scheduled broadcast can be cancelled. Refresh its delivery status.', 409);
            }
            $audit = app(WorkspaceToolsController::class)->audit($request->user('admin')->id, 'broadcast.cancelled', $broadcast, $data['reason'], ['state' => 'cancelled']);

            return ApiResponse::success(['audit_reference' => $audit]);
        });
    }
}
