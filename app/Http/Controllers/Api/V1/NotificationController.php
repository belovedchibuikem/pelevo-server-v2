<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! config('features.notifications')) {
            return ApiResponse::error('FORBIDDEN', 'Notifications are disabled.', 403);
        }
        $data = $request->validate(['view' => ['sometimes', 'in:all,unread,mentions,history,new_episode,broadcast'], 'limit' => ['sometimes', 'integer', 'between:1,50'], 'cursor' => ['sometimes', 'string', 'max:2048']]);
        $view = $data['view'] ?? 'all';
        $query = DB::table('notifications')->where('user_id', $request->user()->id);
        $unread = (clone $query)->whereNull('dismissed_at')->whereNull('read_at')->count();
        $items = $query->when($view !== 'history', fn ($q) => $q->whereNull('dismissed_at'))
            ->when($view === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($view === 'mentions', fn ($q) => $q->whereIn('type', ['mention', 'follow', 'reply']))
            ->when($view === 'new_episode', fn ($q) => $q->where('type', 'new_episode'))
            ->when($view === 'broadcast', fn ($q) => $q->where('type', 'broadcast'))
            ->select('id', 'type', 'title', 'body', 'data', 'read_at', 'dismissed_at', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($data['limit'] ?? 20);

        $publicItems = collect($items->items())->map(function (object $item): array {
            $publicItem = (array) $item;
            foreach (['created_at', 'read_at', 'dismissed_at'] as $field) {
                $publicItem[$field] = $item->$field === null ? null : Carbon::parse($item->$field)->toIso8601String();
            }
            $publicItem['data'] = $this->publicNotificationData($item->data ?? null);

            return $publicItem;
        });

        return ApiResponse::success($publicItems, ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages(), 'unread_count' => $unread]);
    }

    public function read(string $notification, Request $request): JsonResponse
    {
        if (! config('features.notifications')) {
            return ApiResponse::error('FORBIDDEN', 'Notifications are disabled.', 403);
        }
        $query = DB::table('notifications')->where('id', $notification)->where('user_id', $request->user()->id);
        if (! $query->exists()) {
            return ApiResponse::error('NOT_FOUND', 'Notification not found.', 404);
        }
        $query->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['read' => true]);
    }

    public function bulk(Request $request): JsonResponse
    {
        if (! config('features.notifications')) {
            return ApiResponse::error('FORBIDDEN', 'Notifications are disabled.', 403);
        }
        $data = $request->validate(['action' => ['required', 'in:read,dismiss'], 'ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'ulid', 'distinct']]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $query = DB::table('notifications')->where('user_id', $request->user()->id)->whereIn('id', $data['ids']);
            if ($query->lockForUpdate()->get()->count() !== count($data['ids'])) {
                return ApiResponse::error('NOT_FOUND', 'One or more notifications are unavailable.', 404);
            }
            $field = $data['action'] === 'read' ? 'read_at' : 'dismissed_at';
            $query->whereNull($field)->update([$field => now(), 'updated_at' => now()]);

            return ApiResponse::success(['action' => $data['action'], 'ids' => $data['ids']]);
        });
    }

    public function clear(Request $request): JsonResponse
    {
        if (! config('features.notifications')) {
            return ApiResponse::error('FORBIDDEN', 'Notifications are disabled.', 403);
        }
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $count = DB::table('notifications')->where('user_id', $request->user()->id)->whereNull('dismissed_at')->update(['dismissed_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['cleared' => true, 'count' => $count]);
    }

    /**
     * @return array<string, string>
     */
    private function publicNotificationData(mixed $data): array
    {
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($data)) {
            return [];
        }
        $public = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || $value === null || $value === '') {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $public[$key] = is_string($value) ? $value : (string) $value;
            }
        }

        return $public;
    }

    private function mobileOptions(?string $json): array
    {
        return array_replace([
            'in_app_enabled' => true, 'email_enabled' => false, 'badge_enabled' => true,
            'high_priority_only' => false, 'types' => ['new_episodes', 'downloads', 'reminders', 'mentions', 'achievements'],
            'summary_time' => null, 'summary_days' => [], 'summary_include' => [],
        ], $json === null ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function preferences(Request $request): JsonResponse
    {
        $row = DB::table('notification_preferences')->where('user_id', $request->user()->id)->first();

        return ApiResponse::success([
            'user_id' => $request->user()->id, 'version' => (int) ($row?->version ?? 0),
            'new_episodes' => (bool) ($row?->new_episodes ?? true), 'push_enabled' => (bool) ($row?->push_enabled ?? true),
            'quiet_hours_start' => $row?->quiet_hours_start === null ? null : substr($row->quiet_hours_start, 0, 5),
            'quiet_hours_end' => $row?->quiet_hours_end === null ? null : substr($row->quiet_hours_end, 0, 5),
            'timezone' => $row?->timezone ?? 'UTC', 'options' => $this->mobileOptions($row?->mobile_options),
            'delivery_status' => config('features.notifications') ? 'in_app_policy_enabled' : 'preferences_only',
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $types = 'new_episodes,downloads,reminders,mentions,promotions,achievements';
        $data = $request->validate([
            'version' => [$request->isMethod('PATCH') ? 'required' : 'sometimes', 'integer', 'min:0'],
            'new_episodes' => ['required', 'boolean'], 'push_enabled' => ['required', 'boolean'],
            'quiet_hours_start' => ['nullable', 'required_with:quiet_hours_end', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'required_with:quiet_hours_start', 'date_format:H:i', 'different:quiet_hours_start'],
            'timezone' => ['required', 'timezone'],
            'options' => ['sometimes', 'array:in_app_enabled,email_enabled,badge_enabled,high_priority_only,types,summary_time,summary_days,summary_include'],
            'options.in_app_enabled' => ['sometimes', 'boolean'], 'options.email_enabled' => ['sometimes', 'boolean'],
            'options.badge_enabled' => ['sometimes', 'boolean'], 'options.high_priority_only' => ['sometimes', 'boolean'],
            'options.types' => ['sometimes', 'array', 'max:6'], 'options.types.*' => ['required', 'in:'.$types, 'distinct'],
            'options.summary_time' => ['nullable', 'date_format:H:i'],
            'options.summary_days' => ['sometimes', 'array', 'max:7'],
            'options.summary_days.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'options.summary_include' => ['sometimes', 'array', 'max:4'],
            'options.summary_include.*' => ['required', 'in:new_episodes,downloads,mentions,promotions', 'distinct'],
        ]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('notification_preferences')->where('user_id', $request->user()->id)->first();
            $version = (int) ($row?->version ?? 0);
            if (isset($data['version']) && (int) $data['version'] !== $version) {
                return ApiResponse::error('VERSION_CONFLICT', 'Notification preferences changed on another device. Reload before saving.', 409);
            }
            $options = array_replace($this->mobileOptions($row?->mobile_options), $data['options'] ?? []);
            foreach (['in_app_enabled', 'email_enabled', 'badge_enabled', 'high_priority_only'] as $field) {
                $options[$field] = (bool) $options[$field];
            }
            $options['summary_days'] = array_map('intval', $options['summary_days']);
            if ($options['summary_days'] !== [] && ($options['summary_time'] === null || $options['summary_include'] === [])) {
                return ApiResponse::error('VALIDATION', 'Choose a summary time and at least one category, or clear all days to disable the schedule.', 422);
            }
            $values = [...collect($data)->except(['version', 'options'])->all(), 'mobile_options' => json_encode($options, JSON_THROW_ON_ERROR), 'version' => $version + 1, 'updated_at' => now()];
            DB::table('notification_preferences')->updateOrInsert(['user_id' => $request->user()->id], [...$values, ...($row ? [] : ['created_at' => now()])]);

            return $this->preferences($request);
        });
    }

    public function lockRules(Request $request): JsonResponse
    {
        $row = DB::table('notification_lock_rules')->where('user_id', $request->user()->id)->first();

        return ApiResponse::success($this->presentLockRules($row));
    }

    public function updateLockRules(Request $request): JsonResponse
    {
        $data = $request->validate(['show_title' => ['required', 'boolean'], 'show_body' => ['required', 'boolean'], 'sensitive_hidden' => ['required', 'boolean']]);
        DB::table('notification_lock_rules')->updateOrInsert(['user_id' => $request->user()->id], [...$data, 'created_at' => now(), 'updated_at' => now()]);

        return $this->lockRules($request);
    }

    /**
     * @return array{show_title: bool, show_body: bool, sensitive_hidden: bool}
     */
    private function presentLockRules(?object $row): array
    {
        return [
            'show_title' => (bool) ($row->show_title ?? true),
            'show_body' => (bool) ($row->show_body ?? false),
            'sensitive_hidden' => (bool) ($row->sensitive_hidden ?? true),
        ];
    }
}
