<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlatformController extends Controller
{
    public function devices(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('devices')->where('user_id', $request->user()->id)->select('id', 'name', 'platform', 'trust_state', 'last_seen_at', 'last_active_at', 'revoked_at', 'created_at')->orderByDesc('last_seen_at')->get());
    }

    public function pairingCode(Request $request): JsonResponse
    {
        $code = Str::upper(Str::random(8));
        DB::table('device_pairing_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $request->user()->id, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['code' => $code, 'expires_in' => 600], status: 201);
    }

    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:8'], 'device_identifier' => ['required', 'string', 'max:191'], 'name' => ['required', 'string', 'max:191'], 'platform' => ['required', 'string', 'max:30']]);

        return DB::transaction(function () use ($data, $request): JsonResponse {
            $pairing = DB::table('device_pairing_codes')->where('code_hash', hash('sha256', Str::upper($data['code'])))->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $pairing || $pairing->attempts >= 5) {
                return ApiResponse::error('PAIRING_INVALID', 'The pairing code is invalid or expired.', 422);
            }
            DB::table('device_pairing_codes')->where('id', $pairing->id)->update(['used_at' => now(), 'updated_at' => now()]);
            $id = (string) Str::ulid();
            DB::table('devices')->insertOrIgnore(['id' => $id, 'user_id' => $pairing->user_id, 'device_identifier' => $data['device_identifier'], 'name' => $data['name'], 'platform' => $data['platform'], 'trust_state' => 'paired', 'last_active_at' => now(), 'last_ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['paired' => true], status: 201);
        });
    }

    public function revoke(string $device, Request $request): JsonResponse
    {
        $updated = DB::table('devices')->where('id', $device)->where('user_id', $request->user()->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'trust_state' => 'revoked', 'updated_at' => now()]);
        if (! $updated) {
            return ApiResponse::error('NOT_FOUND', 'Active device not found.', 404);
        }
        DB::table('refresh_tokens')->where('device_id', $device)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['revoked' => true]);
    }

    public function signOutAll(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $ids = DB::table('devices')->where('user_id', $request->user()->id)->pluck('id');
            DB::table('devices')->whereIn('id', $ids)->update(['revoked_at' => now(), 'trust_state' => 'revoked', 'updated_at' => now()]);
            DB::table('refresh_tokens')->whereIn('device_id', $ids)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $request->user()->tokens()->delete();
        });

        return ApiResponse::success(['signed_out' => true]);
    }

    public function backup(Request $request): JsonResponse
    {
        if (! config('features.backups')) {
            return ApiResponse::error('FORBIDDEN', 'Backups are disabled.', 403);
        }
        $payload = ['sync' => DB::table('sync_resource_states')->where('user_id', $request->user()->id)->get(), 'preferences' => DB::table('user_preferences')->where('user_id', $request->user()->id)->first()];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $id = (string) Str::ulid();
        DB::table('user_backups')->insert(['id' => $id, 'user_id' => $request->user()->id, 'state' => 'ready', 'version' => 1, 'payload_encrypted' => encrypt($json), 'checksum' => hash('sha256', $json), 'size_bytes' => strlen($json), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['id' => $id, 'state' => 'ready', 'size_bytes' => strlen($json)], status: 201);
    }

    public function restore(string $backup, Request $request): JsonResponse
    {
        if (! config('features.backups')) {
            return ApiResponse::error('FORBIDDEN', 'Backups are disabled.', 403);
        }
        $row = DB::table('user_backups')->where('id', $backup)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Backup not found.', 404);
        }
        $json = decrypt($row->payload_encrypted);
        if (! hash_equals($row->checksum, hash('sha256', $json))) {
            return ApiResponse::error('BACKUP_CORRUPT', 'Backup integrity validation failed.', 409);
        }
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['sync'] as $state) {
            DB::table('sync_resource_states')->updateOrInsert(['user_id' => $request->user()->id, 'resource_type' => $state['resource_type'], 'resource_id' => $state['resource_id']], ['version' => $state['version'], 'payload' => is_string($state['payload']) ? $state['payload'] : json_encode($state['payload'], JSON_THROW_ON_ERROR), 'device_id' => $state['device_id'], 'deleted_at' => $state['deleted_at'], 'updated_at' => now()]);
        }

        return ApiResponse::success(['restored' => true]);
    }

    public function storage(Request $request): JsonResponse
    {
        return ApiResponse::success(['backup_bytes' => (int) DB::table('user_backups')->where('user_id', $request->user()->id)->sum('size_bytes'), 'offline_items' => DB::table('downloads')->where('user_id', $request->user()->id)->count()]);
    }

    public function clearCache(Request $request): JsonResponse
    {
        DB::table('downloads')->where('user_id', $request->user()->id)->delete();

        return ApiResponse::success(['cleared' => true]);
    }

    public function shareTemplates(): JsonResponse
    {
        return ApiResponse::success(DB::table('share_card_templates')->where('active', true)->orderBy('name')->orderBy('version')->get(['id', 'name', 'version'])->map(fn ($row): array => [
            'id' => $row->id,
            'name' => $row->name,
            'version' => (int) $row->version,
        ])->values()->all());
    }

    public function createShare(Request $request): JsonResponse
    {
        if (! config('features.share_cards', true)) {
            return ApiResponse::error('FORBIDDEN', 'Share cards are disabled.', 403);
        }
        $data = $request->validate(['template_id' => ['required', 'exists:share_card_templates,id'], 'subject_type' => ['required', 'in:episode,reel,show'], 'subject_id' => ['required', 'string'], 'payload' => ['required', 'array'], 'scheduled_at' => ['nullable', 'date', 'after:now']]);
        $id = (string) Str::ulid();
        $token = Str::random(32);
        DB::table('share_cards')->insert(['id' => $id, 'user_id' => $request->user()->id, 'share_card_template_id' => $data['template_id'], 'subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id'], 'state' => isset($data['scheduled_at']) ? 'scheduled' : 'ready', 'payload' => json_encode($data['payload'], JSON_THROW_ON_ERROR), 'public_token' => $token, 'scheduled_at' => $data['scheduled_at'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('share_cards')->where('id', $id)->first();
        $name = DB::table('share_card_templates')->where('id', $data['template_id'])->value('name');

        return ApiResponse::success($this->presentedCard($row, is_string($name) ? $name : null), status: 201);
    }

    public function shares(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 20), 1), 50);
        $page = DB::table('share_cards')->where('user_id', $request->user()->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($limit);
        $names = DB::table('share_card_templates')->whereIn('id', collect($page->items())->pluck('share_card_template_id')->unique()->all())->pluck('name', 'id');

        return ApiResponse::success(collect($page->items())->map(fn ($row): array => $this->presentedCard($row, $names[$row->share_card_template_id] ?? null))->values()->all(), ['cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }

    public function share(string $card, Request $request): JsonResponse
    {
        $row = DB::table('share_cards')->where('id', $card)->where('user_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Share card not found.', 404);
        }
        $name = DB::table('share_card_templates')->where('id', $row->share_card_template_id)->value('name');
        $presented = $this->presentedCard($row, is_string($name) ? $name : null);
        $presented['events'] = DB::table('share_card_events')->where('share_card_id', $card)->orderBy('created_at')->get()->map(fn ($event): array => [
            'event' => $event->event,
            'channel' => $event->channel,
            'created_at' => $this->iso($event->created_at),
        ])->values()->all();

        return ApiResponse::success($presented);
    }

    private function presentedCard(object $row, ?string $templateName): array
    {
        $payload = $this->payloadArray($row->payload ?? null);
        $public = [
            'id' => $row->id,
            'template_id' => $row->share_card_template_id,
            'template_name' => $templateName ?? '',
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'state' => $row->state,
            'created_at' => $this->iso($row->created_at),
            'scheduled_at' => $this->iso($row->scheduled_at ?? null),
            'url' => rtrim((string) config('app.url'), '/').'/s/'.$row->public_token,
        ];
        foreach (['variant', 'quote', 'layout'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                $public[$key] = $payload[$key];
            }
        }

        return $public;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toIso8601String();
    }
}
