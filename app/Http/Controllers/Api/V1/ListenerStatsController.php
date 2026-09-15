<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ListenerStatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $progress = DB::table('playback_progress')->where('user_id', $userId);

        return ApiResponse::success([
            'listening_seconds' => (int) (clone $progress)->sum('position_seconds'),
            'episodes_started' => (clone $progress)->count(),
            'episodes_completed' => (clone $progress)->where('completed', true)->count(),
            'shows_followed' => DB::table('follows')->where('user_id', $userId)->count(),
            'episodes_saved' => DB::table('episode_saves')->where('user_id', $userId)->count(),
            'reviews' => DB::table('show_reviews')->where('user_id', $userId)->where('state', 'published')->count(),
            'downloads_count' => Schema::hasTable('downloads')
                ? DB::table('downloads')->where('user_id', $userId)->count()
                : 0,
            'daily' => $this->dailyStats($userId),
            'streak' => $this->streakStats($userId),
            'achievements' => $this->achievements($userId),
            'shows' => $this->topShows($userId),
            'categories' => $this->topCategories($userId),
        ]);
    }

    /**
     * @return list<array{date: string, listening_seconds: int, episodes_started: int, episodes_completed: int}>
     */
    private function dailyStats(string $userId): array
    {
        $fromPlayback = DB::table('playback_progress')
            ->where('user_id', $userId)
            ->where('position_seconds', '>', 0)
            ->selectRaw('DATE(updated_at) as date')
            ->selectRaw('SUM(position_seconds) as listening_seconds')
            ->selectRaw('COUNT(*) as episodes_started')
            ->selectRaw('SUM(CASE WHEN completed THEN 1 ELSE 0 END) as episodes_completed')
            ->groupByRaw('DATE(updated_at)')
            ->orderByDesc('date')
            ->limit(60)
            ->get();

        if ($fromPlayback->isNotEmpty()) {
            return $fromPlayback->map(fn (object $row): array => [
                'date' => Carbon::parse((string) $row->date)->toDateString(),
                'listening_seconds' => (int) $row->listening_seconds,
                'episodes_started' => (int) $row->episodes_started,
                'episodes_completed' => (int) $row->episodes_completed,
            ])->values()->all();
        }

        return DB::table('listening_daily_stats')
            ->where('user_id', $userId)
            ->orderByDesc('date')
            ->limit(30)
            ->get()
            ->map(fn (object $row): array => [
                'date' => Carbon::parse((string) $row->date)->toDateString(),
                'listening_seconds' => (int) $row->listening_seconds,
                'episodes_started' => (int) $row->episodes_started,
                'episodes_completed' => (int) $row->episodes_completed,
            ])->values()->all();
    }

    /**
     * @return array{current_days: int, longest_days: int, last_qualified_date: ?string}
     */
    private function streakStats(string $userId): array
    {
        $days = DB::table('playback_progress')
            ->where('user_id', $userId)
            ->where('position_seconds', '>', 0)
            ->selectRaw('DATE(updated_at) as date')
            ->distinct()
            ->orderByDesc('date')
            ->pluck('date')
            ->map(fn (mixed $date): string => Carbon::parse((string) $date)->toDateString())
            ->unique()
            ->values();

        $stored = DB::table('streaks')->where('user_id', $userId)->first();
        if ($days->isEmpty()) {
            return [
                'current_days' => (int) ($stored?->current_days ?? 0),
                'longest_days' => (int) ($stored?->longest_days ?? 0),
                'last_qualified_date' => $this->dateString($stored?->last_qualified_date ?? null),
            ];
        }

        $qualified = $days->flip();
        $cursor = Carbon::today();
        if (! $qualified->has($cursor->toDateString())) {
            $cursor = $cursor->copy()->subDay();
        }
        $current = 0;
        while ($qualified->has($cursor->toDateString())) {
            $current++;
            $cursor = $cursor->subDay();
        }

        $sorted = $days->map(fn (string $date): Carbon => Carbon::parse($date))->sort()->values();
        $longest = 1;
        $run = 1;
        for ($i = 1; $i < $sorted->count(); $i++) {
            if ($sorted[$i]->copy()->subDay()->isSameDay($sorted[$i - 1])) {
                $run++;
                $longest = max($longest, $run);
            } else {
                $run = 1;
            }
        }
        $longest = max($longest, $current, (int) ($stored?->longest_days ?? 0));
        $last = $days->first();

        DB::table('streaks')->updateOrInsert(
            ['user_id' => $userId],
            [
                'current_days' => $current,
                'longest_days' => $longest,
                'last_qualified_date' => $last,
                'updated_at' => now(),
                'created_at' => $stored?->created_at ?? now(),
            ],
        );

        return [
            'current_days' => $current,
            'longest_days' => $longest,
            'last_qualified_date' => $last,
        ];
    }

    /**
     * @return list<array{slug: string, name: string, description: string, earned_at: string}>
     */
    private function achievements(string $userId): array
    {
        if (! Schema::hasTable('user_achievements')) {
            return [];
        }

        return DB::table('user_achievements')
            ->join('achievements', 'achievements.id', '=', 'user_achievements.achievement_id')
            ->where('user_achievements.user_id', $userId)
            ->orderByDesc('user_achievements.earned_at')
            ->get(['achievements.slug', 'achievements.name', 'achievements.description', 'user_achievements.earned_at'])
            ->map(fn (object $row): array => [
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'description' => (string) ($row->description ?? ''),
                'earned_at' => Carbon::parse((string) $row->earned_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @return list<array{id: string, title: string, artwork_url: ?string, listening_seconds: int, episodes_completed: int}>
     */
    private function topShows(string $userId): array
    {
        return DB::table('playback_progress')
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
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'artwork_url' => $row->artwork_url !== null ? (string) $row->artwork_url : null,
                'listening_seconds' => (int) $row->listening_seconds,
                'episodes_completed' => (int) $row->episodes_completed,
            ])->values()->all();
    }

    /**
     * @return list<array{id: string, name: string, slug: string, listening_seconds: int}>
     */
    private function topCategories(string $userId): array
    {
        return DB::table('playback_progress')
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
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'listening_seconds' => (int) $row->listening_seconds,
            ])->values()->all();
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
