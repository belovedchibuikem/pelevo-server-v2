<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ListenerStatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $progress = DB::table('playback_progress')->where('user_id', $userId);
        $streak = DB::table('streaks')->where('user_id', $userId)->first();

        return ApiResponse::success([
            'listening_seconds' => (int) (clone $progress)->sum('position_seconds'),
            'episodes_started' => (clone $progress)->count(),
            'episodes_completed' => (clone $progress)->where('completed', true)->count(),
            'shows_followed' => DB::table('follows')->where('user_id', $userId)->count(),
            'episodes_saved' => DB::table('episode_saves')->where('user_id', $userId)->count(),
            'reviews' => DB::table('show_reviews')->where('user_id', $userId)->where('state', 'published')->count(),
            'downloads_count' => DB::table('downloads')->where('user_id', $userId)->count(),
            'daily' => DB::table('listening_daily_stats')->where('user_id', $userId)->orderByDesc('date')->limit(30)->get()->map(fn (object $row): array => [
                'date' => $row->date,
                'listening_seconds' => (int) $row->listening_seconds,
                'episodes_started' => (int) $row->episodes_started,
                'episodes_completed' => (int) $row->episodes_completed,
            ])->values()->all(),
            'streak' => $streak === null ? null : [
                'current_days' => (int) $streak->current_days,
                'longest_days' => (int) $streak->longest_days,
                'last_qualified_date' => $streak->last_qualified_date,
            ],
            'achievements' => DB::table('user_achievements')
                ->join('achievements', 'achievements.id', '=', 'user_achievements.achievement_id')
                ->where('user_achievements.user_id', $userId)
                ->orderByDesc('user_achievements.earned_at')
                ->get(['achievements.slug', 'achievements.name', 'achievements.description', 'user_achievements.earned_at'])
                ->map(fn (object $row): array => [
                    'slug' => $row->slug,
                    'name' => $row->name,
                    'description' => $row->description,
                    'earned_at' => $row->earned_at,
                ])->values()->all(),
            'shows' => DB::table('playback_progress')
                ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
                ->join('shows', 'shows.id', '=', 'episodes.show_id')
                ->where('playback_progress.user_id', $userId)
                ->groupBy('shows.id', 'shows.title', 'shows.artwork_url')
                ->orderByDesc(DB::raw('SUM(playback_progress.position_seconds)'))
                ->limit(10)
                ->get([
                    'shows.id',
                    'shows.title',
                    'shows.artwork_url',
                    DB::raw('SUM(playback_progress.position_seconds) as listening_seconds'),
                    DB::raw('SUM(CASE WHEN playback_progress.completed THEN 1 ELSE 0 END) as episodes_completed'),
                ])
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'title' => $row->title,
                    'artwork_url' => $row->artwork_url,
                    'listening_seconds' => (int) $row->listening_seconds,
                    'episodes_completed' => (int) $row->episodes_completed,
                ])->values()->all(),
            'categories' => DB::table('playback_progress')
                ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
                ->join('category_show', 'category_show.show_id', '=', 'episodes.show_id')
                ->join('categories', 'categories.id', '=', 'category_show.category_id')
                ->where('playback_progress.user_id', $userId)
                ->groupBy('categories.id', 'categories.name', 'categories.slug')
                ->orderByDesc(DB::raw('SUM(playback_progress.position_seconds)'))
                ->limit(10)
                ->get([
                    'categories.id',
                    'categories.name',
                    'categories.slug',
                    DB::raw('SUM(playback_progress.position_seconds) as listening_seconds'),
                ])
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'listening_seconds' => (int) $row->listening_seconds,
                ])->values()->all(),
        ]);
    }
}
