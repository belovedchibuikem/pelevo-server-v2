<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\BuildHomeFeed;
use App\Http\Controllers\Controller;
use App\Jobs\MaterializeHomeFeed;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class HomeController extends Controller
{
    public function feed(Request $request, BuildHomeFeed $builder): JsonResponse
    {
        $userId = $request->user()->id;
        $snapshot = DB::table('home_feed_snapshots')->where('user_id', $userId)->where('expires_at', '>', now())->first();
        $snapshotRails = $snapshot ? json_decode($snapshot->rails, true, flags: JSON_THROW_ON_ERROR) : null;
        $validSnapshot = is_array($snapshotRails) && isset($snapshotRails[0]['id'], $snapshotRails[0]['type']);
        $moduleVersion = hash('sha256', (string) DB::table('home_modules')->max('updated_at'));
        $rails = $validSnapshot ? $snapshotRails : Cache::flexible("home:feed:{$userId}:v2:{$moduleVersion}", [30, 120], fn (): array => $builder->handle($userId));
        if (! $validSnapshot) {
            MaterializeHomeFeed::dispatch($userId)->afterResponse();
        }

        return ApiResponse::success(['schema_version' => 2, 'rails' => $rails], ['freshness' => $validSnapshot ? 'materialized' : 'computed', 'snapshot_version' => $validSnapshot ? $snapshot?->version : null]);
    }

    public function rail(Request $request, BuildHomeFeed $builder, string $rail): JsonResponse
    {
        $filters = $request->validate([
            'min_duration' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'max_duration' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'country' => ['sometimes', 'string', 'size:2', Rule::in(['NG', 'GH', 'KE', 'ZA', 'UG', 'TZ', 'RW', 'ET', 'CM', 'SN', 'CI'])],
        ]);
        $module = $builder->rail($request->user()->id, $rail, $filters);
        abort_unless($module, 404);

        return ApiResponse::success(['schema_version' => 2, 'rail' => $module]);
    }

    public function discover(): JsonResponse
    {
        $data = Cache::flexible('home:discover:v2', [60, 300], function (): array {
            $editorial = DB::table('editorial_playlists')
                ->where('published', true)
                ->orderBy('position')
                ->orderBy('id')
                ->limit(12)
                ->get(['id', 'title', 'description', 'artwork_url']);

            return [
                'editors_picks' => $editorial->map(fn (object $playlist): array => [
                    'id' => $playlist->id,
                    'type' => 'editorial_playlist',
                    'title' => $playlist->title,
                    'subtitle' => $playlist->description,
                    'artwork_url' => $playlist->artwork_url,
                ])->all(),
                'trending' => $this->trendingEpisodes(10),
                'new_episodes' => DB::table('episodes')
                    ->join('shows', 'shows.id', '=', 'episodes.show_id')
                    ->where('episodes.availability', 'available')
                    ->where('shows.status', 'active')
                    ->select('episodes.id', 'episodes.title', 'episodes.published_at', 'episodes.duration_seconds', 'shows.title as show_title', 'shows.artwork_url')
                    ->orderByDesc('episodes.published_at')
                    ->orderByDesc('episodes.id')
                    ->limit(30)
                    ->get()
                    ->map(fn (object $episode): array => [
                        'id' => $episode->id,
                        'type' => 'episode',
                        'title' => $episode->title,
                        'show_title' => $episode->show_title,
                        'artwork_url' => $episode->artwork_url,
                        'duration_seconds' => $episode->duration_seconds,
                        'published_at' => $episode->published_at,
                    ])->all(),
                'categories' => DB::table('categories')->where('active', true)->orderBy('position')->orderBy('id')->limit(30)->get(['id', 'name', 'slug']),
            ];
        });

        return ApiResponse::success($data, ['freshness' => 'cached']);
    }

    public function charts(): JsonResponse
    {
        $data = Cache::flexible('home:charts:v2', [60, 300], fn (): array => [
            'podcasts' => $this->rankedShows(),
            'music' => $this->rankedShows('music'),
            'audiobooks' => $this->rankedShows('audiobooks'),
        ]);

        return ApiResponse::success($data, ['freshness' => 'cached']);
    }

    public function playlists(Request $request): JsonResponse
    {
        $editorial = Cache::flexible('home:playlists:editorial:v2', [60, 300], fn (): array => $this->editorialPlaylists());
        $live = Cache::flexible('home:playlists:live:v1', [15, 60], fn (): array => $this->liveNow());
        $yours = DB::table('playlists')
            ->leftJoin('playlist_items', 'playlist_items.playlist_id', '=', 'playlists.id')
            ->where('playlists.user_id', $request->user()->id)
            ->select('playlists.id', 'playlists.name', 'playlists.updated_at', DB::raw('COUNT(playlist_items.id) as item_count'))
            ->groupBy('playlists.id', 'playlists.name', 'playlists.updated_at')
            ->orderByDesc('playlists.updated_at')
            ->limit(20)
            ->get()
            ->map(fn (object $playlist): array => [
                'id' => $playlist->id,
                'type' => 'playlist',
                'title' => $playlist->name,
                'item_count' => (int) $playlist->item_count,
            ])
            ->all();

        return ApiResponse::success([
            'yours' => $yours,
            'editorial' => $editorial,
            'live' => $live,
        ], ['freshness' => 'mixed']);
    }

    public function recommendations(Request $request): JsonResponse
    {
        if (! config('features.recommendations')) {
            return ApiResponse::error('FORBIDDEN', 'Recommendations are disabled.', 403);
        }
        $snapshot = DB::table('recommendation_snapshots')->where('user_id', $request->user()->id)->where('expires_at', '>', now())->latest('version')->first();
        $raw = $snapshot ? json_decode($snapshot->items, true) : [];
        $items = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '' || ! is_string($row['title'] ?? null) || $row['title'] === '') {
                continue;
            }
            $items[] = [
                'id' => $row['id'],
                'show_id' => is_string($row['show_id'] ?? null) && $row['show_id'] !== '' ? $row['show_id'] : null,
                'title' => $row['title'],
                'reason' => is_string($row['reason'] ?? null) && $row['reason'] !== '' ? $row['reason'] : null,
            ];
        }

        return ApiResponse::success([
            'version' => $snapshot?->version,
            'items' => $items,
        ], ['freshness' => $snapshot ? 'materialized' : 'fallback']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trendingEpisodes(int $limit): array
    {
        return DB::table('episodes')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->leftJoin('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->where('episodes.availability', 'available')
            ->where('shows.status', 'active')
            ->select(
                'episodes.id',
                'episodes.title',
                'episodes.duration_seconds',
                'episodes.published_at',
                'shows.title as show_title',
                'shows.artwork_url',
                DB::raw('COUNT(DISTINCT playback_progress.user_id) as listener_count'),
            )
            ->groupBy('episodes.id', 'episodes.title', 'episodes.duration_seconds', 'episodes.published_at', 'shows.title', 'shows.artwork_url')
            ->orderByDesc('listener_count')
            ->orderByDesc('episodes.published_at')
            ->limit($limit)
            ->get()
            ->values()
            ->map(fn (object $episode, int $index): array => [
                'id' => $episode->id,
                'type' => 'episode',
                'title' => $episode->title,
                'show_title' => $episode->show_title,
                'artwork_url' => $episode->artwork_url,
                'duration_seconds' => $episode->duration_seconds,
                'rank' => $index + 1,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rankedShows(?string $categorySlug = null): array
    {
        $query = DB::table('shows')
            ->leftJoin('follows', 'follows.show_id', '=', 'shows.id')
            ->leftJoin('show_ratings', 'show_ratings.show_id', '=', 'shows.id')
            ->where('shows.status', 'active')
            ->when($categorySlug !== null, function ($shows) use ($categorySlug): void {
                $shows->join('category_show', 'category_show.show_id', '=', 'shows.id')
                    ->join('categories', 'categories.id', '=', 'category_show.category_id')
                    ->where('categories.slug', $categorySlug)
                    ->where('categories.active', true);
            })
            ->select(
                'shows.id',
                'shows.title',
                'shows.author',
                'shows.artwork_url',
                DB::raw('COUNT(DISTINCT follows.user_id) as followers'),
                DB::raw('COALESCE(AVG(show_ratings.rating), 0) as rating'),
            )
            ->groupBy('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url')
            ->orderByDesc('followers')
            ->orderByDesc('rating')
            ->orderBy('shows.id')
            ->limit(50)
            ->get();

        return $query->values()->map(fn (object $show, int $index): array => [
            'id' => $show->id,
            'type' => 'show',
            'title' => $show->title,
            'subtitle' => $show->author,
            'artwork_url' => $show->artwork_url,
            'followers' => (int) $show->followers,
            'rating' => round((float) $show->rating, 2),
            'rank' => $index + 1,
        ])->all();
    }

    /**
     * @return list<object>
     */
    private function editorialPlaylists(): array
    {
        $playlists = DB::table('editorial_playlists')->where('published', true)->orderBy('position')->orderBy('id')->limit(20)->get();
        $items = DB::table('editorial_playlist_items')
            ->join('episodes', 'episodes.id', '=', 'editorial_playlist_items.episode_id')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->whereIn('editorial_playlist_items.editorial_playlist_id', $playlists->pluck('id'))
            ->where('episodes.availability', 'available')
            ->select('editorial_playlist_items.editorial_playlist_id', 'editorial_playlist_items.position', 'episodes.id', 'episodes.title', 'shows.title as show_title', 'shows.artwork_url')
            ->orderBy('editorial_playlist_items.position')
            ->get()
            ->groupBy('editorial_playlist_id');

        return $playlists->map(function (object $playlist) use ($items): object {
            $playlist->items = $items->get($playlist->id, collect())->take(50)->values();

            return $playlist;
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveNow(): array
    {
        return DB::table('live_sessions')
            ->join('creator_profiles', 'creator_profiles.id', '=', 'live_sessions.creator_profile_id')
            ->leftJoin('shows', 'shows.id', '=', 'live_sessions.show_id')
            ->whereIn('live_sessions.state', ['scheduled', 'live'])
            ->orderByRaw("live_sessions.state = 'live' desc")
            ->orderBy('live_sessions.scheduled_at')
            ->limit(20)
            ->get([
                'live_sessions.id',
                'live_sessions.title',
                'live_sessions.state',
                'live_sessions.viewer_count',
                'creator_profiles.display_name as host',
                'shows.artwork_url',
            ])
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'type' => 'live',
                'title' => $session->title,
                'subtitle' => $session->host,
                'viewer_count' => (int) $session->viewer_count,
                'state' => $session->state,
                'artwork_url' => $session->artwork_url,
            ])
            ->all();
    }
}
