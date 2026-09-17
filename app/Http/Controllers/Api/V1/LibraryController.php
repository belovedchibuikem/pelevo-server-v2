<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $playlists = DB::table('playlists')->where('user_id', $userId)->orderByDesc('updated_at')->limit(20)->get();
        $collections = DB::table('collections')->where('user_id', $userId)->orderByDesc('updated_at')->limit(20)->get();

        return ApiResponse::success([
            'saved_count' => DB::table('episode_saves')->where('user_id', $userId)->count(),
            'liked_count' => DB::table('episode_reactions')->where('user_id', $userId)->whereIn('reaction', ['like', 'love'])->count(),
            'shows_count' => DB::table('follows')->where('user_id', $userId)->count(),
            'downloads_count' => DB::table('downloads')->where('user_id', $userId)->count(),
            'recent_count' => DB::table('playback_progress')->where('user_id', $userId)->count(),
            'playlists' => $this->presentedPlaylists($playlists),
            'collections' => $this->presentedCollections($collections),
        ]);
    }

    public function saved(Request $request): JsonResponse
    {
        return $this->page(
            DB::table('episode_saves')
                ->join('episodes', 'episodes.id', '=', 'episode_saves.episode_id')
                ->join('shows', 'shows.id', '=', 'episodes.show_id')
                ->where('episode_saves.user_id', $request->user()->id)
                ->select(
                    'episodes.id',
                    'episodes.show_id',
                    'episodes.title',
                    'shows.title as show_title',
                    'shows.artwork_url',
                    'episodes.duration_seconds',
                    'episode_saves.created_at as saved_at',
                )
                ->orderByDesc('episode_saves.created_at')
                ->orderByDesc('episodes.id'),
            $request,
            fn (object $row): array => $this->presentedEpisode($row, [
                'saved_at' => $this->iso($row->saved_at),
            ]),
        );
    }

    public function liked(Request $request): JsonResponse
    {
        return $this->page(
            DB::table('episode_reactions')
                ->join('episodes', 'episodes.id', '=', 'episode_reactions.episode_id')
                ->join('shows', 'shows.id', '=', 'episodes.show_id')
                ->where('episode_reactions.user_id', $request->user()->id)
                ->whereIn('episode_reactions.reaction', ['like', 'love'])
                ->select(
                    'episodes.id',
                    'episodes.show_id',
                    'episodes.title',
                    'shows.title as show_title',
                    'shows.artwork_url',
                    'episodes.duration_seconds',
                    'episode_reactions.reaction',
                    'episode_reactions.updated_at as reacted_at',
                )
                ->orderByDesc('episode_reactions.updated_at')
                ->orderByDesc('episodes.id'),
            $request,
            fn (object $row): array => $this->presentedEpisode($row, [
                'reaction' => $row->reaction,
                'reacted_at' => $this->iso($row->reacted_at),
            ]),
        );
    }

    public function shows(Request $request): JsonResponse
    {
        return $this->page(
            DB::table('follows')
                ->join('shows', 'shows.id', '=', 'follows.show_id')
                ->where('follows.user_id', $request->user()->id)
                ->select(
                    'shows.id',
                    'shows.title',
                    'shows.author',
                    'shows.artwork_url',
                    'follows.notifications_enabled',
                    'follows.created_at as followed_at',
                )
                ->orderByDesc('follows.created_at')
                ->orderByDesc('shows.id'),
            $request,
            fn (object $row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'author' => $row->author,
                'artwork_url' => $row->artwork_url,
                'notifications_enabled' => (bool) $row->notifications_enabled,
                'followed_at' => $this->iso($row->followed_at),
            ],
        );
    }

    public function recent(Request $request): JsonResponse
    {
        return $this->page(
            DB::table('playback_progress')
                ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
                ->join('shows', 'shows.id', '=', 'episodes.show_id')
                ->where('playback_progress.user_id', $request->user()->id)
                ->select(
                    'episodes.id',
                    'episodes.show_id',
                    'episodes.title',
                    'shows.title as show_title',
                    'shows.artwork_url',
                    'episodes.duration_seconds',
                    'playback_progress.position_seconds',
                    'playback_progress.completed',
                    'playback_progress.updated_at as played_at',
                )
                ->orderByDesc('playback_progress.updated_at')
                ->orderByDesc('episodes.id'),
            $request,
            fn (object $row): array => $this->presentedEpisode($row, [
                'position_seconds' => (int) $row->position_seconds,
                'completed' => (bool) $row->completed || $this->progressLooksComplete($row),
                'played_at' => $this->iso($row->played_at),
            ]),
        );
    }

    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->presentedSettings($request->user()->id));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['hide_completed' => ['required', 'boolean'], 'sort' => ['required', 'in:recent,title,oldest'], 'version' => ['required', 'integer', 'min:0']]);
        $row = DB::table('library_settings')->where('user_id', $request->user()->id)->first();
        if (($row?->version ?? 0) !== $data['version']) {
            return ApiResponse::error('VERSION_CONFLICT', 'Library settings changed on another device.', 409);
        }
        $values = ['hide_completed' => $data['hide_completed'], 'sort' => $data['sort'], 'version' => ($row?->version ?? 0) + 1, 'updated_at' => now()];
        $row
            ? DB::table('library_settings')->where('user_id', $request->user()->id)->update($values)
            : DB::table('library_settings')->insert(['user_id' => $request->user()->id, ...$values, 'created_at' => now()]);

        return ApiResponse::success($this->presentedSettings($request->user()->id));
    }

    public function saveEpisode(string $episode, Request $request): JsonResponse
    {
        DB::table('episode_saves')->insertOrIgnore(['user_id' => $request->user()->id, 'episode_id' => $episode, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['saved' => true]);
    }

    public function unsaveEpisode(string $episode, Request $request): JsonResponse
    {
        DB::table('episode_saves')->where('user_id', $request->user()->id)->where('episode_id', $episode)->delete();

        return ApiResponse::success(['saved' => false]);
    }

    public function playlists(Request $request): JsonResponse
    {
        return $this->page(
            DB::table('playlists')->where('user_id', $request->user()->id)->orderByDesc('updated_at')->orderByDesc('id'),
            $request,
            fn (object $row): array => $this->presentedPlaylist($row, $this->playlistCounts([$row->id])[$row->id] ?? 0),
        );
    }

    public function showPlaylist(string $playlist, Request $request): JsonResponse
    {
        $row = DB::table('playlists')->where('id', $playlist)->where('user_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Playlist not found.', 404);
        }
        $items = DB::table('playlist_items')
            ->join('episodes', 'episodes.id', '=', 'playlist_items.episode_id')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('playlist_items.playlist_id', $row->id)
            ->orderBy('playlist_items.position')
            ->get([
                'episodes.id',
                'episodes.show_id',
                'episodes.title',
                'shows.title as show_title',
                'shows.artwork_url',
                'episodes.duration_seconds',
                'playlist_items.position',
            ]);

        return ApiResponse::success([
            ...$this->presentedPlaylist($row, $items->count()),
            'items' => $items->map(fn (object $item): array => $this->presentedEpisode($item, [
                'position' => (int) $item->position,
            ]))->values()->all(),
        ]);
    }

    public function storePlaylist(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'], 'is_public' => ['sometimes', 'boolean']]);
        $id = (string) Str::ulid();
        DB::table('playlists')->insert(['id' => $id, 'user_id' => $request->user()->id, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_public' => $data['is_public'] ?? false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->presentedPlaylist(DB::table('playlists')->find($id), 0), status: 201);
    }

    public function updatePlaylist(string $playlist, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'], 'is_public' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $updated = DB::table('playlists')->where('id', $playlist)->where('user_id', $request->user()->id)->where('version', $data['version'])->update(['name' => $data['name'], 'description' => $data['description'], 'is_public' => $data['is_public'], 'version' => $data['version'] + 1, 'updated_at' => now()]);
        if (! $updated) {
            return DB::table('playlists')->where('id', $playlist)->where('user_id', $request->user()->id)->exists()
                ? ApiResponse::error('VERSION_CONFLICT', 'Playlist changed on another device.', 409)
                : ApiResponse::error('NOT_FOUND', 'Playlist not found.', 404);
        }
        $row = DB::table('playlists')->find($playlist);

        return ApiResponse::success($this->presentedPlaylist($row, $this->playlistCounts([$row->id])[$row->id] ?? 0));
    }

    public function deletePlaylist(string $playlist, Request $request): JsonResponse
    {
        $deleted = DB::table('playlists')->where('id', $playlist)->where('user_id', $request->user()->id)->delete();

        return $deleted ? ApiResponse::success(['deleted' => true]) : ApiResponse::error('NOT_FOUND', 'Playlist not found.', 404);
    }

    public function addPlaylistItem(string $playlist, Request $request): JsonResponse
    {
        $data = $request->validate([
            'episode_id' => ['required', 'string', 'exists:episodes,id'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($playlist, $request, $data): JsonResponse {
            $row = DB::table('playlists')->where('id', $playlist)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Playlist not found.', 404);
            }
            if ((int) $row->version !== (int) $data['version']) {
                return ApiResponse::error('VERSION_CONFLICT', 'Playlist changed on another device.', 409);
            }
            $exists = DB::table('playlist_items')
                ->where('playlist_id', $row->id)
                ->where('episode_id', $data['episode_id'])
                ->exists();
            if ($exists) {
                return ApiResponse::success($this->presentedPlaylist($row, $this->playlistCounts([$row->id])[$row->id] ?? 0));
            }
            $position = (int) (DB::table('playlist_items')->where('playlist_id', $row->id)->max('position') ?? -1) + 1;
            DB::table('playlist_items')->insert([
                'id' => (string) Str::ulid(),
                'playlist_id' => $row->id,
                'episode_id' => $data['episode_id'],
                'position' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('playlists')->where('id', $row->id)->update([
                'version' => $row->version + 1,
                'updated_at' => now(),
            ]);
            $updated = DB::table('playlists')->find($row->id);

            return ApiResponse::success($this->presentedPlaylist($updated, $this->playlistCounts([$row->id])[$row->id] ?? 0));
        });
    }

    public function collections(Request $request): JsonResponse
    {
        return $this->page(
            DB::table('collections')->where('user_id', $request->user()->id)->orderByDesc('updated_at')->orderByDesc('id'),
            $request,
            fn (object $row): array => $this->presentedCollection($row, $this->collectionCounts([$row->id])[$row->id] ?? 0),
        );
    }

    public function showCollection(string $collection, Request $request): JsonResponse
    {
        $row = DB::table('collections')->where('id', $collection)->where('user_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Collection not found.', 404);
        }
        $rows = DB::table('collection_items')->where('collection_id', $row->id)->orderBy('created_at')->get();
        $episodeIds = $rows->where('collectable_type', 'episode')->pluck('collectable_id')->all();
        $showIds = $rows->where('collectable_type', 'show')->pluck('collectable_id')->all();
        $episodes = $episodeIds === [] ? collect() : DB::table('episodes')->join('shows', 'shows.id', '=', 'episodes.show_id')->whereIn('episodes.id', $episodeIds)->get(['episodes.id', 'episodes.show_id', 'episodes.title', 'shows.title as show_title', 'shows.artwork_url', 'episodes.duration_seconds'])->keyBy('id');
        $shows = $showIds === [] ? collect() : DB::table('shows')->whereIn('id', $showIds)->get(['id', 'title', 'author', 'artwork_url'])->keyBy('id');
        $items = [];
        foreach ($rows as $item) {
            if ($item->collectable_type === 'episode' && $episodes->has($item->collectable_id)) {
                $items[] = $this->presentedEpisode($episodes[$item->collectable_id], ['collectable_type' => 'episode']);
            }
            if ($item->collectable_type === 'show' && $shows->has($item->collectable_id)) {
                $show = $shows[$item->collectable_id];
                $items[] = [
                    'id' => $show->id,
                    'collectable_type' => 'show',
                    'title' => $show->title,
                    'author' => $show->author,
                    'artwork_url' => $show->artwork_url,
                ];
            }
        }

        return ApiResponse::success([
            ...$this->presentedCollection($row, count($items)),
            'items' => $items,
        ]);
    }

    public function storeCollection(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $id = (string) Str::ulid();
        DB::table('collections')->insert(['id' => $id, 'user_id' => $request->user()->id, 'name' => $data['name'], 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->presentedCollection(DB::table('collections')->find($id), 0), status: 201);
    }

    public function updateCollection(string $collection, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'version' => ['required', 'integer', 'min:1']]);
        $updated = DB::table('collections')->where('id', $collection)->where('user_id', $request->user()->id)->where('version', $data['version'])->update(['name' => $data['name'], 'version' => $data['version'] + 1, 'updated_at' => now()]);
        if (! $updated) {
            return DB::table('collections')->where('id', $collection)->where('user_id', $request->user()->id)->exists()
                ? ApiResponse::error('VERSION_CONFLICT', 'Collection changed on another device.', 409)
                : ApiResponse::error('NOT_FOUND', 'Collection not found.', 404);
        }
        $row = DB::table('collections')->find($collection);

        return ApiResponse::success($this->presentedCollection($row, $this->collectionCounts([$row->id])[$row->id] ?? 0));
    }

    public function deleteCollection(string $collection, Request $request): JsonResponse
    {
        $deleted = DB::table('collections')->where('id', $collection)->where('user_id', $request->user()->id)->delete();

        return $deleted ? ApiResponse::success(['deleted' => true]) : ApiResponse::error('NOT_FOUND', 'Collection not found.', 404);
    }

    private function page($query, Request $request, callable $map): JsonResponse
    {
        $page = $query->cursorPaginate(min(max($request->integer('limit', 20), 1), 50));

        return ApiResponse::success(
            collect($page->items())->map($map)->values()->all(),
            ['cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()],
        );
    }

    private function presentedEpisode(object $row, array $extra = []): array
    {
        return [
            'id' => $row->id,
            'show_id' => $row->show_id,
            'title' => $row->title,
            'show_title' => $row->show_title,
            'artwork_url' => $row->artwork_url,
            'duration_seconds' => $row->duration_seconds === null ? null : (int) $row->duration_seconds,
            ...$extra,
        ];
    }

    private function progressLooksComplete(object $row): bool
    {
        $duration = (int) ($row->duration_seconds ?? 0);
        $position = (int) ($row->position_seconds ?? 0);
        if ($duration < 30 || $position <= 0) {
            return false;
        }

        return ($duration - $position) <= 15 || $position >= (int) floor($duration * 0.95);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentedPlaylists($rows): array
    {
        $counts = $this->playlistCounts($rows->pluck('id')->all());

        return $rows->map(fn (object $row): array => $this->presentedPlaylist($row, $counts[$row->id] ?? 0))->values()->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentedCollections($rows): array
    {
        $counts = $this->collectionCounts($rows->pluck('id')->all());

        return $rows->map(fn (object $row): array => $this->presentedCollection($row, $counts[$row->id] ?? 0))->values()->all();
    }

    private function presentedPlaylist(object $row, int $itemCount): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'description' => $row->description,
            'is_public' => (bool) $row->is_public,
            'version' => (int) $row->version,
            'item_count' => $itemCount,
        ];
    }

    private function presentedCollection(object $row, int $itemCount): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'version' => (int) $row->version,
            'item_count' => $itemCount,
        ];
    }

    private function presentedSettings(string $userId): array
    {
        $row = DB::table('library_settings')->where('user_id', $userId)->first();

        return [
            'hide_completed' => (bool) ($row?->hide_completed ?? false),
            'sort' => $row?->sort ?? 'recent',
            'version' => (int) ($row?->version ?? 0),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function playlistCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('playlist_items')->whereIn('playlist_id', $ids)->selectRaw('playlist_id, COUNT(*) as item_count')->groupBy('playlist_id')->pluck('item_count', 'playlist_id')->map(fn ($count): int => (int) $count)->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function collectionCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('collection_items')->whereIn('collection_id', $ids)->selectRaw('collection_id, COUNT(*) as item_count')->groupBy('collection_id')->pluck('item_count', 'collection_id')->map(fn ($count): int => (int) $count)->all();
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
