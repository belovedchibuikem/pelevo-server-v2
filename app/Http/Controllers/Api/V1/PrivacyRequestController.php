<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareDataExport;
use App\Jobs\ProcessAccountDeletion;
use App\Mail\PelevoNotice;
use App\Services\MailPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivacyRequestController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $existing = DB::table('data_export_requests')->where('user_id', $request->user()->id)->whereIn('state', ['queued', 'processing'])->first();
        if ($existing) {
            return ApiResponse::success($existing);
        }
        $id = (string) Str::ulid();
        DB::table('data_export_requests')->insert(['id' => $id, 'user_id' => $request->user()->id, 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        PrepareDataExport::dispatch($id)->afterCommit();

        return ApiResponse::success(['id' => $id, 'state' => 'queued'], status: 202);
    }

    public function exportStatus(Request $request, string $export): JsonResponse
    {
        $record = DB::table('data_export_requests')->where('id', $export)->where('user_id', $request->user()->id)->first();
        abort_unless($record, 404);
        $downloadUrl = $record->state === 'completed' && $record->expires_at && now()->lt($record->expires_at)
            ? URL::temporarySignedRoute('api.me.data-export.download', now()->addMinutes(5), ['export' => $record->id])
            : null;

        return ApiResponse::success([
            'id' => $record->id,
            'state' => $record->state,
            'completed_at' => $record->completed_at,
            'expires_at' => $record->expires_at,
            'download_url' => $downloadUrl,
        ]);
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $record = DB::table('data_export_requests')->where('id', $export)->where('user_id', $request->user()->id)->first();

        return $this->streamExport($record);
    }

    public function publicDownload(string $export): StreamedResponse
    {
        return $this->streamExport(DB::table('data_export_requests')->where('id', $export)->first());
    }

    public function delete(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'current_password'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $existing = DB::table('account_deletion_requests')->where('user_id', $request->user()->id)->where('state', 'queued')->first();
        if ($existing) {
            return ApiResponse::success($existing);
        }
        $id = (string) Str::ulid();
        $scheduled = now()->addDays(30);
        DB::table('account_deletion_requests')->insert(['id' => $id, 'user_id' => $request->user()->id, 'state' => 'queued', 'reason' => $data['reason'] ?? null, 'scheduled_for' => $scheduled, 'created_at' => now(), 'updated_at' => now()]);
        ProcessAccountDeletion::dispatch($id)->delay($scheduled)->afterCommit();
        app(MailPreference::class)->queueToUser($request->user()->id, new PelevoNotice(
            subjectLine: 'Your Pelevo account deletion is scheduled',
            eyebrow: 'Account',
            heading: 'We will delete your account in 30 days',
            intro: 'Your Pelevo account is scheduled for deletion on '.$scheduled->toDayDateTimeString().'. Sign back in before then if you want to keep it.',
            detail: $data['reason'] ? 'Reason you gave: '.$data['reason'] : null,
        ));

        return ApiResponse::success(['id' => $id, 'state' => 'queued', 'scheduled_for' => $scheduled->toIso8601String()], status: 202);
    }

    private function streamExport(?object $record): StreamedResponse
    {
        abort_unless($record, 404);
        abort_if($record->state !== 'completed' || ! $record->path || now()->gte($record->expires_at), 410, 'This export is no longer available.');
        abort_unless(Storage::disk($record->disk)->exists($record->path), 404);

        return Storage::disk($record->disk)->download($record->path, "pelevo-data-{$record->id}.json", ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
