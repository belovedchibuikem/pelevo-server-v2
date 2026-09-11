<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchNotificationBroadcast;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class AdvancedOperationsController extends Controller
{
    public function page(): Response
    {
        return Inertia::render('Admin/Advanced', $this->data());
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->data());
    }

    public function cms(Request $request): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'alpha_dash', 'max:100'], 'title' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'max:100000'], 'state' => ['required', 'in:draft,published'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $current = DB::table('cms_pages')->where('slug', $data['slug'])->first();
        $id = $current?->id ?? (string) Str::ulid();
        $version = ($current?->version ?? 0) + 1;
        DB::table('cms_pages')->updateOrInsert(['slug' => $data['slug']], ['id' => $id, 'title' => $data['title'], 'body' => $data['body'], 'version' => $version, 'state' => $data['state'], 'published_at' => $data['state'] === 'published' ? now() : null, 'updated_by' => auth('admin')->id(), 'created_at' => $current?->created_at ?? now(), 'updated_at' => now()]);
        $audit = $this->audit($request, 'cms.saved', 'App\\Models\\CmsPage', $id, $data['reason'], ['version' => $version, 'state' => $data['state']]);

        return ApiResponse::success(['page' => DB::table('cms_pages')->find($id), 'audit_reference' => $audit]);
    }

    public function prompt(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:summary,chapters,transcript'], 'prompt' => ['required', 'string', 'max:50000'], 'rollout_percent' => ['required', 'integer', 'between:0,100'], 'state' => ['required', 'in:draft,active,retired'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $version = (int) DB::table('prompt_versions')->where('type', $data['type'])->max('version') + 1;
        $id = (string) Str::ulid();
        DB::transaction(function () use ($data, $version, $id): void {
            if ($data['state'] === 'active') {
                DB::table('prompt_versions')->where('type', $data['type'])->where('state', 'active')->update(['state' => 'retired', 'updated_at' => now()]);
            } DB::table('prompt_versions')->insert(['id' => $id, ...collect($data)->except('reason')->all(), 'version' => $version, 'created_at' => now(), 'updated_at' => now()]);
        });
        $audit = $this->audit($request, 'ai_prompt.created', 'App\\Models\\PromptVersion', $id, $data['reason'], ['version' => $version]);

        return ApiResponse::success(['prompt' => DB::table('prompt_versions')->find($id), 'audit_reference' => $audit], status: 201);
    }

    public function referralProgram(Request $request): JsonResponse
    {
        $data = $request->validate(['qualifying_seconds' => ['required', 'integer', 'min:60'], 'referrer_reward' => ['required', 'integer', 'min:1'], 'referred_reward' => ['required', 'integer', 'min:1'], 'daily_cap' => ['required', 'integer', 'min:1'], 'state' => ['required', 'in:draft,active,retired'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $version = (int) DB::table('referral_programs')->max('version') + 1;
        $id = (string) Str::ulid();
        DB::transaction(function () use ($data, $version, $id): void {
            if ($data['state'] === 'active') {
                DB::table('referral_programs')->where('state', 'active')->update(['state' => 'retired', 'updated_at' => now()]);
            } DB::table('referral_programs')->insert(['id' => $id, ...collect($data)->except('reason')->all(), 'version' => $version, 'effective_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $audit = $this->audit($request, 'referral_program.created', 'App\\Models\\ReferralProgram', $id, $data['reason'], ['version' => $version]);

        return ApiResponse::success(['program' => DB::table('referral_programs')->find($id), 'audit_reference' => $audit], status: 201);
    }

    public function support(string $ticket, Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'in:open,pending,resolved,closed'], 'priority' => ['required', 'in:normal,high,urgent'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $updated = DB::table('support_tickets')->where('id', $ticket)->update(['state' => $data['state'], 'priority' => $data['priority'], 'assigned_admin_id' => auth('admin')->id(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        if (! $updated) {
            return ApiResponse::error('NOT_FOUND', 'Ticket not found.', 404);
        }
        $audit = $this->audit($request, 'support.updated', 'App\\Models\\SupportTicket', $ticket, $data['reason'], $data);

        return ApiResponse::success(['ticket' => DB::table('support_tickets')->find($ticket), 'audit_reference' => $audit]);
    }

    public function notificationTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'alpha_dash', 'max:100'], 'title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $version = (int) DB::table('notification_templates')->where('key', $data['key'])->max('version') + 1;
        $id = (string) Str::ulid();
        DB::table('notification_templates')->insert(['id' => $id, 'key' => $data['key'], 'version' => $version, 'title' => $data['title'], 'body' => $data['body'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $audit = $this->audit($request, 'notification_template.created', 'App\\Models\\NotificationTemplate', $id, $data['reason'], ['version' => $version]);

        return ApiResponse::success(['template' => DB::table('notification_templates')->find($id), 'audit_reference' => $audit], status: 201);
    }

    public function broadcast(Request $request): JsonResponse
    {
        abort_unless(config('features.notifications'), 409, 'Notifications are currently disabled.');
        $data = $request->validate(['template_id' => ['required', 'exists:notification_templates,id'], 'audience' => ['present', 'array:country'], 'audience.country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'], 'scheduled_at' => ['nullable', 'date', 'after:now'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        abort_unless(DB::table('notification_templates')->where('id', $data['template_id'])->where('active', true)->exists(), 422, 'Choose an active template.');
        $id = (string) Str::ulid();
        DB::table('notification_broadcasts')->insert(['id' => $id, 'notification_template_id' => $data['template_id'], 'audience' => json_encode($data['audience'], JSON_THROW_ON_ERROR), 'state' => 'scheduled', 'scheduled_at' => $data['scheduled_at'] ?? now(), 'created_by' => auth('admin')->id(), 'created_at' => now(), 'updated_at' => now()]);
        DispatchNotificationBroadcast::dispatch($id)->afterCommit();
        $audit = $this->audit($request, 'broadcast.scheduled', 'App\\Models\\NotificationBroadcast', $id, $data['reason'], ['scheduled_at' => $data['scheduled_at'] ?? now()]);

        return ApiResponse::success(['broadcast' => DB::table('notification_broadcasts')->find($id), 'audit_reference' => $audit], status: 201);
    }

    private function data(): array
    {
        return ['referrals' => DB::table('referrals')->latest()->limit(100)->get(), 'programs' => DB::table('referral_programs')->latest('version')->get(), 'cmsPages' => DB::table('cms_pages')->orderBy('slug')->get(), 'aiJobs' => DB::table('ai_jobs')->latest()->limit(100)->get(), 'aiUsage' => ['cost_microusd' => DB::table('ai_usage')->sum('cost_microusd'), 'jobs' => DB::table('ai_jobs')->count()], 'prompts' => DB::table('prompt_versions')->latest('version')->get(), 'tickets' => DB::table('support_tickets')->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")->orderBy('sla_due_at')->limit(100)->get(), 'feedback' => DB::table('feedback')->latest()->limit(100)->get(), 'broadcasts' => DB::table('notification_broadcasts')->latest()->limit(100)->get(), 'deliveries' => DB::table('notification_deliveries')->latest()->limit(100)->get(), 'syncConflicts' => DB::table('sync_conflicts')->where('state', 'open')->latest()->limit(100)->get(), 'backups' => DB::table('user_backups')->selectRaw('state, count(*) total, sum(size_bytes) bytes')->groupBy('state')->get(), 'freshAt' => now()->toIso8601String()];
    }

    private function audit(Request $request, string $action, string $type, string $id, string $reason, array $after): string
    {
        $audit = (string) Str::ulid();
        DB::table('audit_logs')->insert(['id' => $audit, 'admin_id' => auth('admin')->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'reason' => $reason, 'after' => json_encode($after), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return $audit;
    }
}
