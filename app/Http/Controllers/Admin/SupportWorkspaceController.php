<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PelevoNotice;
use App\Services\InAppNotificationDelivery;
use App\Services\MailPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportWorkspaceController extends Controller
{
    private const LINK_TYPES = [
        'ticket' => ['Support ticket', 'support_tickets', 'users.view', '/admin/support/tickets/'],
        'webhook' => ['Webhook incident', 'provider_webhook_events', 'audit.view', '/admin/audit?view=webhooks&q='],
        'failed_job' => ['Failed job', 'failed_jobs', 'audit.view', '/admin/audit?view=failed-jobs&q='],
        'reconciliation' => ['Reconciliation discrepancy', 'reconciliation_items', 'finance.view', '/admin/finance-records?view=reconciliation&q='],
        'report' => ['Moderation report', 'content_reports', 'moderation.act', '/admin/community?view=reports&q='],
        'episode' => ['Episode', 'episodes', 'catalog.write', '/admin/records/episodes/'],
    ];

    public function show(Request $request, string $ticket): Response
    {
        $row = DB::table('support_tickets')->find($ticket);
        abort_unless($row, 404);

        return Inertia::render('Admin/SupportTicket', [
            'ticket' => $row,
            'requester' => DB::table('users')->where('id', $row->user_id)->first(['id', 'name', 'handle', 'status']),
            'notes' => DB::table('support_ticket_notes')->join('admins', 'admins.id', '=', 'support_ticket_notes.admin_id')->where('support_ticket_id', $ticket)->orderBy('support_ticket_notes.created_at')->get(['support_ticket_notes.id', 'body', 'public_reply', 'admins.name as author', 'support_ticket_notes.created_at']),
            'attachments' => DB::table('support_ticket_attachments')->where('support_ticket_id', $ticket)->latest()->get(['id', 'name', 'mime', 'size', 'created_at']),
            'linkTypes' => collect($this->allowedLinks($request))->map(fn (array $type, string $key): array => ['key' => $key, 'label' => $type[0]])->values(),
            'links' => DB::table('support_ticket_links')->where('support_ticket_id', $ticket)->whereIn('kind', array_keys($this->allowedLinks($request)))->latest()->get(['id', 'kind', 'target_id', 'reason', 'created_at'])->map(fn ($link): array => [...(array) $link, 'label' => self::LINK_TYPES[$link->kind][0], 'href' => self::LINK_TYPES[$link->kind][3].rawurlencode($link->target_id)]),
            'audit' => DB::table('audit_logs')->where('subject_id', $ticket)->latest()->limit(100)->get(['id', 'action', 'reason', 'created_at']),
            'assignees' => DB::table('admins')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'freshAt' => now()->toIso8601String(),
        ]);
    }

    public function update(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'state' => ['required', 'in:open,pending,resolved,closed'], 'priority' => ['required', 'in:normal,high,urgent'], 'assigned_admin_id' => ['nullable', Rule::exists('admins', 'id')->where('status', 'active')], 'note' => ['nullable', 'string', 'max:10000'], 'reply' => ['nullable', 'string', 'max:10000'], 'confirm_reply' => ['sometimes', 'accepted'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);

        if (! empty($data['reply']) && ! $request->boolean('confirm_reply')) {
            return ApiResponse::error('VALIDATION', 'Confirm that this reply should be sent to the requester.', 422);
        }

        return DB::transaction(function () use ($data, $ticket, $request): JsonResponse {
            $row = DB::table('support_tickets')->where('id', $ticket)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->version !== $data['version']) {
                return ApiResponse::error('CONFLICT', 'Another operator changed this ticket. Reload its latest version before saving; your note has been preserved.', 409);
            }
            $values = ['state' => $data['state'], 'priority' => $data['priority'], 'assigned_admin_id' => $data['assigned_admin_id'] ?? null, 'version' => $row->version + 1, 'updated_at' => now()];
            DB::table('support_tickets')->where('id', $ticket)->update($values);
            if (! empty($data['note'])) {
                DB::table('support_ticket_notes')->insert(['id' => (string) Str::ulid(), 'support_ticket_id' => $ticket, 'admin_id' => $request->user('admin')->id, 'body' => $data['note'], 'created_at' => now()]);
            }
            if (! empty($data['reply'])) {
                $replyId = (string) Str::ulid();
                DB::table('support_ticket_notes')->insert(['id' => $replyId, 'support_ticket_id' => $ticket, 'admin_id' => $request->user('admin')->id, 'body' => $data['reply'], 'public_reply' => true, 'created_at' => now()]);
                app(InAppNotificationDelivery::class)->deliver($row->user_id, ['type' => 'support_reply', 'key' => 'support-reply:'.$replyId, 'title' => $row->subject, 'body' => $data['reply'], 'data' => ['support_ticket_id' => $ticket]]);
                app(MailPreference::class)->queueToUser((string) $row->user_id, new PelevoNotice(
                    subjectLine: 'Pelevo support: '.$row->subject,
                    eyebrow: 'Support',
                    heading: 'A reply on your support ticket',
                    intro: 'The Pelevo team replied to “'.$row->subject.'”.',
                    detail: $data['reply'],
                ));
            }
            $audit = (string) Str::ulid();
            DB::table('audit_logs')->insert(['id' => $audit, 'admin_id' => $request->user('admin')->id, 'subject_type' => 'App\\Models\\SupportTicket', 'subject_id' => $ticket, 'action' => 'support.updated', 'reason' => $data['reason'], 'before' => json_encode(['state' => $row->state, 'priority' => $row->priority, 'assigned_admin_id' => $row->assigned_admin_id, 'version' => $row->version]), 'after' => json_encode($values), 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['version' => $values['version'], 'audit_reference' => $audit]);
        }, 3);
    }

    public function inquiry(Request $request, string $inquiry): Response
    {
        $row = DB::table('contact_inquiries')->find($inquiry);
        abort_unless($row, 404);
        $matchedUser = DB::table('users')->where('email', $row->email)->first(['id', 'name', 'handle', 'status']);

        return Inertia::render('Admin/ContactInquiry', [
            'inquiry' => $row,
            'matchedUser' => $matchedUser,
            'audit' => DB::table('audit_logs')->where('subject_id', $inquiry)->latest()->limit(50)->get(['id', 'action', 'reason', 'created_at']),
            'freshAt' => now()->toIso8601String(),
        ]);
    }

    public function updateInquiry(Request $request, string $inquiry): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'in:new,open,pending,resolved,closed'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return DB::transaction(function () use ($data, $inquiry, $request): JsonResponse {
            $row = DB::table('contact_inquiries')->where('id', $inquiry)->lockForUpdate()->first();
            abort_unless($row, 404);
            $values = ['state' => $data['state'], 'updated_at' => now()];
            DB::table('contact_inquiries')->where('id', $inquiry)->update($values);
            $audit = (string) Str::ulid();
            DB::table('audit_logs')->insert([
                'id' => $audit,
                'admin_id' => $request->user('admin')->id,
                'subject_type' => 'contact_inquiry',
                'subject_id' => $inquiry,
                'action' => 'support.contact_updated',
                'reason' => $data['reason'],
                'before' => json_encode(['state' => $row->state]),
                'after' => json_encode($values),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Cache::forget('admin:module-counts:support');

            return ApiResponse::success(['audit_reference' => $audit]);
        }, 3);
    }

    public function attach(Request $request, string $ticket): JsonResponse
    {
        abort_unless(DB::table('support_tickets')->where('id', $ticket)->exists(), 404);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,txt', 'extensions:jpg,jpeg,png,pdf,txt', 'max:5120'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $file = $request->file('file');
        $path = $file->store('support-attachments/'.$ticket, 'local');
        abort_unless($path, 503, 'Attachment storage is unavailable.');
        $id = (string) Str::ulid();
        try {
            DB::transaction(function () use ($request, $ticket, $data, $file, $path, $id): void {
                DB::table('support_ticket_attachments')->insert(['id' => $id, 'support_ticket_id' => $ticket, 'admin_id' => $request->user('admin')->id, 'name' => Str::limit(preg_replace('/[^\pL\pN._ -]/u', '_', $file->getClientOriginalName()), 175, ''), 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
                app(WorkspaceToolsController::class)->audit($request->user('admin')->id, 'support.attachment_added', $ticket, $data['reason'], ['attachment_id' => $id]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return ApiResponse::success(['id' => $id], status: 201);
    }

    public function attachment(Request $request, string $ticket, string $attachment): StreamedResponse
    {
        $row = DB::table('support_ticket_attachments')->where('support_ticket_id', $ticket)->where('id', $attachment)->first();
        abort_unless($row && Storage::disk('local')->exists($row->path), 404);
        app(WorkspaceToolsController::class)->audit($request->user('admin')->id, 'support.attachment_downloaded', $ticket, 'Reviewed private support evidence', ['attachment_id' => $attachment]);

        return Storage::disk('local')->download($row->path, $row->name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function link(Request $request, string $ticket): JsonResponse
    {
        abort_unless(DB::table('support_tickets')->where('id', $ticket)->exists(), 404);
        $types = $this->allowedLinks($request);
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys($types))], 'target_id' => ['required', 'string', 'max:191'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        abort_unless(DB::table($types[$data['kind']][1])->where('id', $data['target_id'])->exists(), 404);

        return DB::transaction(function () use ($ticket, $request, $data): JsonResponse {
            DB::table('support_tickets')->where('id', $ticket)->lockForUpdate()->firstOrFail();
            $existing = DB::table('support_ticket_links')->where('support_ticket_id', $ticket)->where('kind', $data['kind'])->where('target_id', $data['target_id'])->first();
            if ($existing) {
                return ApiResponse::success(['id' => $existing->id]);
            }
            $id = (string) Str::ulid();
            DB::table('support_ticket_links')->insert(['id' => $id, 'support_ticket_id' => $ticket, 'admin_id' => $request->user('admin')->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            $audit = app(WorkspaceToolsController::class)->audit($request->user('admin')->id, 'support.record_linked', $ticket, $data['reason'], ['kind' => $data['kind'], 'target_id' => $data['target_id']]);

            return ApiResponse::success(['id' => $id, 'audit_reference' => $audit], status: 201);
        }, 3);
    }

    private function allowedLinks(Request $request): array
    {
        $permissions = \App\Support\AdminAccess::permissions($request->user('admin'));

        return array_filter(self::LINK_TYPES, fn (array $type): bool => $permissions->contains($type[2]));
    }
}
