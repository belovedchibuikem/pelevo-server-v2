<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DownloadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            DB::table('downloads')
                ->join('episodes', 'episodes.id', '=', 'downloads.episode_id')
                ->join('shows', 'shows.id', '=', 'episodes.show_id')
                ->where('downloads.user_id', $request->user()->id)
                ->orderByDesc('downloads.updated_at')
                ->limit(100)
                ->get([
                    'downloads.id as download_id',
                    'downloads.authorized_until',
                    'episodes.id',
                    'episodes.show_id',
                    'episodes.title',
                    'shows.title as show_title',
                    'shows.artwork_url',
                ])
                ->map(fn (object $row): array => [
                    'download_id' => $row->download_id,
                    'authorized_until' => $row->authorized_until,
                    'id' => $row->id,
                    'show_id' => $row->show_id,
                    'title' => $row->title,
                    'show_title' => $row->show_title,
                    'artwork_url' => $row->artwork_url,
                ])
                ->values()
                ->all()
        );
    }

    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->presentedSettings($request->user()->id));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['wifi_only' => ['required', 'boolean'], 'auto_delete_completed' => ['required', 'boolean'], 'max_storage_mb' => ['required', 'integer', 'between:128,65536'], 'version' => ['required', 'integer', 'min:0']]);
        $row = DB::table('download_settings')->where('user_id', $request->user()->id)->first();
        if (($row?->version ?? 0) !== $data['version']) {
            return ApiResponse::error('VERSION_CONFLICT', 'Download settings changed on another device.', 409);
        }
        $values = [...collect($data)->except('version')->all(), 'version' => ($row?->version ?? 0) + 1, 'updated_at' => now()];
        $row ? DB::table('download_settings')->where('user_id', $request->user()->id)->update($values) : DB::table('download_settings')->insert(['user_id' => $request->user()->id, ...$values, 'created_at' => now()]);

        return ApiResponse::success($this->presentedSettings($request->user()->id));
    }

    public function destroy(string $download, Request $request): JsonResponse
    {
        $deleted = DB::table('downloads')->where('id', $download)->where('user_id', $request->user()->id)->delete();
        if (! $deleted) {
            return ApiResponse::error('NOT_FOUND', 'Download not found.', 404);
        }

        return ApiResponse::success(['deleted' => true]);
    }

    private function presentedSettings(string $userId): array
    {
        $row = DB::table('download_settings')->where('user_id', $userId)->first();

        return [
            'wifi_only' => (bool) ($row?->wifi_only ?? true),
            'auto_delete_completed' => (bool) ($row?->auto_delete_completed ?? false),
            'max_storage_mb' => (int) ($row?->max_storage_mb ?? 2048),
            'version' => (int) ($row?->version ?? 0),
        ];
    }
}
