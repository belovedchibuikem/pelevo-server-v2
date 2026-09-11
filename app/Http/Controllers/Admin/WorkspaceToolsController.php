<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAdminExport;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkspaceToolsController extends Controller
{
    private const PERMISSIONS = ['finance-records' => 'finance.view', 'users' => 'users.view', 'reels-live' => 'moderation.act', 'community' => 'moderation.act', 'communications' => 'broadcast.send', 'growth' => 'settings.write', 'cms' => 'settings.write', 'ai' => 'ai.manage', 'support' => 'users.view', 'analytics' => 'audit.view', 'settings' => 'settings.write', 'audit' => 'audit.view'];

    public static function allowed(string $adminId, string $module): bool
    {
        return isset(self::PERMISSIONS[$module]) && DB::table('admins')->where('id', $adminId)->where('status', 'active')->exists()
            && DB::table('admin_role')->join('permission_role', 'permission_role.role_id', '=', 'admin_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('admin_role.admin_id', $adminId)->where('permissions.name', self::PERMISSIONS[$module])->exists();
    }

    public function index(Request $request, string $module): JsonResponse
    {
        abort_unless(self::allowed($request->user('admin')->id, $module), 403);
        $views = DB::table('admin_saved_views')->where('module', $module)->where(fn ($q) => $q->where('admin_id', $request->user('admin')->id)->orWhere('shared', true))->orderBy('name')->get()->map(function ($row) use ($request): array {
            return ['id' => $row->id, 'name' => $row->name, 'shared' => (bool) $row->shared, 'owned' => $row->admin_id === $request->user('admin')->id, 'filters' => json_decode($row->filters, true)];
        });
        $exports = DB::table('admin_exports')->where('module', $module)->where('admin_id', $request->user('admin')->id)->latest()->limit(10)->get(['id', 'state', 'row_count', 'expires_at', 'created_at']);

        return ApiResponse::success(['views' => $views, 'exports' => $exports]);
    }

    public function save(Request $request, string $module): JsonResponse
    {
        abort_unless(self::allowed($request->user('admin')->id, $module), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'shared' => ['required', 'boolean'], ...['layout' => ['sometimes', 'array:columns,compact'], 'layout.columns' => ['required_with:layout', 'array', 'min:1', 'max:100'], 'layout.columns.*' => ['string', 'max:80', 'regex:/^[a-z_]+$/'], 'layout.compact' => ['required_with:layout', 'boolean']], ...$this->filterRules()]);
        app(ModuleWorkspaceController::class)->exportQuery($module, $data['filters']);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $request, $module, $data): void {
            DB::table('admin_saved_views')->insert(['id' => $id, 'admin_id' => $request->user('admin')->id, 'module' => $module, 'name' => $data['name'], 'shared' => $data['shared'], 'filters' => json_encode([...$data['filters'], 'layout' => $data['layout'] ?? null], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request->user('admin')->id, 'saved_view.created', $id, 'Saved operator view', ['module' => $module, 'shared' => $data['shared']]);
        });

        return ApiResponse::success(['id' => $id], status: 201);
    }

    public function destroy(Request $request, string $module, string $view): JsonResponse
    {
        abort_unless(self::allowed($request->user('admin')->id, $module), 403);
        $record = DB::table('admin_saved_views')->where('id', $view)->where('module', $module)->where('admin_id', $request->user('admin')->id)->first();
        abort_unless($record, 404);
        DB::transaction(function () use ($request, $view): void {
            DB::table('admin_saved_views')->where('id', $view)->delete();
            $this->audit($request->user('admin')->id, 'saved_view.deleted', $view, 'Removed operator view', []);
        });

        return ApiResponse::success();
    }

    public function export(Request $request, string $module): JsonResponse
    {
        abort_unless(self::allowed($request->user('admin')->id, $module), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000'], ...$this->filterRules()]);
        app(ModuleWorkspaceController::class)->exportQuery($module, $data['filters']);
        $id = (string) Str::ulid();
        $audit = DB::transaction(function () use ($request, $module, $data, $id): string {
            DB::table('admin_exports')->insert(['id' => $id, 'admin_id' => $request->user('admin')->id, 'module' => $module, 'filters' => json_encode($data['filters'], JSON_THROW_ON_ERROR), 'reason' => $data['reason'], 'state' => 'queued', 'disk' => config('exports.admin_disk'), 'expires_at' => now()->addHours(config('exports.retention_hours')), 'created_at' => now(), 'updated_at' => now()]);
            GenerateAdminExport::dispatch($id)->onQueue('admin-exports')->afterCommit();

            return $this->audit($request->user('admin')->id, 'export.requested', $id, $data['reason'], ['module' => $module]);
        });

        return ApiResponse::success(['id' => $id, 'audit_reference' => $audit], status: 202);
    }

    public function download(Request $request, string $module, string $export): StreamedResponse
    {
        abort_unless(self::allowed($request->user('admin')->id, $module), 403);
        $record = DB::table('admin_exports')->where('id', $export)->where('module', $module)->where('admin_id', $request->user('admin')->id)->first();
        abort_unless($record, 404);
        abort_if(now()->gte($record->expires_at), 410, 'This export has expired. Request a new export.');
        abort_unless($record->state === 'completed' && $record->path && Storage::disk($record->disk)->exists($record->path), 409, 'The export is not ready.');
        $this->audit($request->user('admin')->id, 'export.downloaded', $export, $record->reason, ['row_count' => $record->row_count]);

        return Storage::disk($record->disk)->download($record->path, "pelevo-{$module}-{$export}.csv", ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function filterRules(): array
    {
        return ['filters' => ['required', 'array:view,q,state,unit,date_from,date_to,sort,direction,per_page'], 'filters.view' => ['required', 'string', 'max:60'], 'filters.q' => ['nullable', 'string', 'max:100'], 'filters.unit' => ['nullable', 'string', 'size:3', 'alpha:ascii'], 'filters.state' => ['nullable', 'string', 'max:40'], 'filters.date_from' => ['nullable', 'date_format:Y-m-d'], 'filters.date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filters.date_from'], 'filters.sort' => ['nullable', 'string', 'max:40'], 'filters.direction' => ['nullable', 'in:asc,desc'], 'filters.per_page' => ['nullable', 'in:25,50,100']];
    }

    public function audit(string $adminId, string $action, string $id, string $reason, array $after): string
    {
        $reference = (string) Str::ulid();
        DB::table('audit_logs')->insert(['id' => $reference, 'admin_id' => $adminId, 'action' => $action, 'subject_type' => 'AdminWorkspace', 'subject_id' => $id, 'reason' => $reason, 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $reference;
    }
}
