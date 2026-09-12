<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Actions\Catalog\PersistDiscoveredShow;
use App\Http\Controllers\Controller;
use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Jobs\HydrateRssFeed;
use App\Models\Episode;
use App\Models\Show;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CatalogController extends Controller
{
    public function search(Request $request, PodcastIndexClient $client, PersistDiscoveredShow $persist): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
            'language' => ['nullable', 'string', 'max:35'],
            'category' => ['nullable', 'string', 'max:60'],
            'preview' => ['nullable', 'boolean'],
        ]);
        $normalizedQuery = trim($data['q']);
        $limit = $data['limit'] ?? 20;
        $preview = $request->boolean('preview');
        $category = isset($data['category']) ? trim((string) $data['category']) : null;
        $category = $category === '' ? null : $category;
        $queryHash = hash('sha256', Str::lower($normalizedQuery.'|'.($category ?? '')));
        if (! $preview) {
            $history = DB::table('search_history')->where('user_id', $request->user()->id)->where('query_hash', $queryHash)->first();
            $history ? DB::table('search_history')->where('id', $history->id)->update(['query' => $normalizedQuery, 'searched_at' => now(), 'updated_at' => now()]) : DB::table('search_history')->insert(['id' => (string) Str::ulid(), 'user_id' => $request->user()->id, 'query_hash' => $queryHash, 'query' => $normalizedQuery, 'searched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $shows = $this->matchingShows($normalizedQuery, $limit);
        $freshness = 'local';
        // Preview (typeahead) also hits Podcast Index so typing finds shows, but
        // skips search_history writes.
        if (config('services.podcast_index.enabled')) {
            try {
                $feeds = $client->searchByTerm($normalizedQuery, $limit, $data['language'] ?? null, $category)['feeds'] ?? [];
                if ($feeds === [] && $category !== null) {
                    $feeds = $client->trending($category, $limit, $data['language'] ?? null)['feeds'] ?? [];
                }
                $merged = collect();
                $persisted = 0;
                foreach ($feeds as $feed) {
                    if ($show = $persist->handle(is_array($feed) ? $feed : [])) {
                        $merged->put($show->id, $show);
                        $persisted++;
                    }
                }
                // Prefer Podcast Index hits by id — do not re-filter with LIKE, which drops
                // valid discoveries when the title does not contain the exact query string.
                foreach ($shows as $show) {
                    $merged->put($show->id, $show);
                }
                $shows = $merged->take($limit)->values();
                $freshness = 'fresh';
                Log::info('catalog.search.podcast_index', [
                    'query' => $normalizedQuery,
                    'preview' => $preview,
                    'feeds' => count($feeds),
                    'persisted' => $persisted,
                    'returned' => $shows->count(),
                ]);
            } catch (PodcastIndexException $exception) {
                $freshness = 'stale';
                Log::warning('catalog.search.podcast_index_failed', [
                    'query' => $normalizedQuery,
                    'preview' => $preview,
                    'message' => $exception->getMessage(),
                ]);
            }
        } else {
            Log::info('catalog.search.podcast_index_skipped', [
                'query' => $normalizedQuery,
                'reason' => 'disabled',
            ]);
        }
        if (! $preview) {
            DB::table('search_history')->where('user_id', $request->user()->id)->where('query_hash', $queryHash)->update(['result_count' => $shows->count(), 'updated_at' => now()]);
        }

        return ApiResponse::success([
            'query' => $normalizedQuery,
            'shows' => $shows->map(fn (Show $show): array => $this->presentShowCard($show))->values(),
            'episodes' => $this->matchingEpisodes($normalizedQuery, $limit),
            'playlists' => $this->matchingPlaylists($normalizedQuery, $limit),
        ], ['cursor' => null, 'has_more' => false, 'freshness' => $freshness]);
    }

    public function show(Show $show, Request $request): JsonResponse
    {
        if ($destination = DB::table('show_redirects')->where('source_show_id', $show->id)->value('destination_show_id')) {
            return ApiResponse::success(['redirect_to' => $destination], ['canonical_location' => route('api.show', $destination)], 308)->header('Location', route('api.show', $destination));
        }

        $this->ensureRssHydrationQueued($show);
        $show->load('feedState');
        $payload = $this->presentShowDetail($show, $request);

        return ApiResponse::success($payload);
    }

    public function episodes(Show $show, Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
        ]);
        $this->ensureRssHydrationQueued($show);
        $show->loadMissing('feedState');

        $query = $show->episodes()->where('availability', 'available');
        $search = isset($data['q']) ? trim((string) $data['q']) : '';
        if ($search !== '') {
            $like = '%'.$search.'%';
            $fulltext = $this->fullTextClause(['title', 'description'], $search);
            $query->where(function ($builder) use ($like, $fulltext): void {
                if ($fulltext !== null) {
                    $builder->whereRaw($fulltext['match'], $fulltext['bindings']);
                }
                $this->applyLikeFallback($builder, ['title', 'description'], $like);
            });
        }

        $items = $query->orderByDesc('published_at')->orderByDesc('id')->cursorPaginate($data['limit'] ?? 20);

        return ApiResponse::success(collect($items->items())->map(fn (Episode $episode): array => $this->presentEpisode($episode, $show))->values(), [
            'cursor' => $items->nextCursor()?->encode(),
            'has_more' => $items->hasMorePages(),
            'feed_state' => $show->feedState?->state,
            'episodes_syncing' => $this->episodesSyncing($show),
            'q' => $search !== '' ? $search : null,
        ]);
    }

    public function episode(Episode $episode): JsonResponse
    {
        abort_unless($episode->availability === 'available', 404);
        $episode->load('show');

        return ApiResponse::success($this->presentEpisode($episode, $episode->show, detailed: true));
    }

    public function voiceSearch(Request $request, PodcastIndexClient $client, PersistDiscoveredShow $persist): JsonResponse
    {
        $data = $request->validate(['transcript' => ['required', 'string', 'min:3', 'max:100'], 'language' => ['nullable', 'string', 'max:35']]);
        $request->merge(['q' => $data['transcript']]);

        return $this->search($request, $client, $persist);
    }

    public function related(Show $show): JsonResponse
    {
        $categoryIds = DB::table('category_show')->where('show_id', $show->id)->pluck('category_id');
        $related = Show::query()->where('shows.id', '!=', $show->id)->where('shows.status', 'active')->when($categoryIds->isNotEmpty(), fn ($query) => $query->join('category_show', 'category_show.show_id', '=', 'shows.id')->whereIn('category_show.category_id', $categoryIds))->when($categoryIds->isEmpty(), fn ($query) => $query->where(fn ($fallback) => $fallback->where('language', $show->language)->orWhere('author', $show->author)))->select('shows.id', 'shows.title', 'shows.artwork_url', 'shows.author')->distinct()->orderBy('shows.title')->orderBy('shows.id')->limit(20)->get();

        return ApiResponse::success($related->map(fn (Show $item): array => [
            'id' => $item->id,
            'title' => $item->title,
            'author' => $item->author,
            'artwork_url' => $item->artwork_url,
        ])->values());
    }

    public function following(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['sometimes', 'integer', 'between:1,50'], 'cursor' => ['sometimes', 'string', 'max:2048']]);
        $items = DB::table('follows')->join('shows', 'shows.id', '=', 'follows.show_id')->where('follows.user_id', $request->user()->id)->select('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url', 'follows.notifications_enabled', 'follows.created_at as followed_at')->orderByDesc('followed_at')->orderByDesc('shows.id')->cursorPaginate($request->integer('limit', 20));

        return ApiResponse::success($items->items(), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }

    public function notifications(Show $show, Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $updated = DB::table('follows')->where('user_id', $request->user()->id)->where('show_id', $show->id)->update(['notifications_enabled' => $data['enabled'], 'updated_at' => now()]);
        if (! $updated) {
            return ApiResponse::error('NOT_FOLLOWING', 'Follow the show before changing notifications.', 409);
        }

        return ApiResponse::success(['notifications_enabled' => $data['enabled']]);
    }

    public function follow(Show $show, Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $request->user()->followedShows()->syncWithoutDetaching([$show->id => ['notifications_enabled' => true]]);
        $cache->user($request->user()->id);
        $cache->public();
        $this->ensureRssHydrationQueued($show);

        return ApiResponse::success(['following' => true]);
    }

    public function unfollow(Show $show, Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $request->user()->followedShows()->detach($show);
        $cache->user($request->user()->id);
        $cache->public();

        return ApiResponse::success(['following' => false]);
    }

    public function rate(Show $show, Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5']]);
        $request->user()->ratedShows()->syncWithoutDetaching([$show->id => ['rating' => $data['rating']]]);
        $cache->public();

        return ApiResponse::success(['rating' => $data['rating']]);
    }

    public function refresh(Show $show, Request $request): JsonResponse
    {
        $key = 'show-refresh:'.$request->user()->id.':'.$show->id;
        $emptyShow = ! $show->episodes()->exists();
        // Empty / still-syncing shows should always be allowed to retry.
        if (! $emptyShow && ! Cache::add($key, true, now()->addMinutes(5))) {
            return ApiResponse::error('RATE_LIMITED', 'This show was refreshed recently.', 429);
        }
        Cache::forget('show-hydrate:'.$show->id);
        $show->feedState()->updateOrCreate([], [
            'state' => 'pending',
            'next_poll_at' => now(),
            'last_error' => null,
        ]);
        $this->dispatchShowHydration($show, preferInline: $emptyShow);

        return ApiResponse::success([
            'queued' => true,
            'episodes_syncing' => true,
        ], status: 202);
    }

    private function matchingShows(string $query, int $limit)
    {
        $like = '%'.$query.'%';
        $fulltext = $this->fullTextClause(['title', 'description', 'author'], $query);

        return Show::query()
            ->where('status', 'active')
            ->where(function ($builder) use ($query, $like, $fulltext): void {
                if ($fulltext !== null) {
                    $builder->whereRaw($fulltext['match'], $fulltext['bindings']);
                }
                $this->applyLikeFallback($builder, ['title', 'description', 'author'], $like);
                $builder->orWhere('rss_url', $query);
            })
            ->when(
                $fulltext !== null,
                fn ($builder) => $builder->orderByRaw($fulltext['rank'], $fulltext['bindings'])
            )
            ->orderBy('title')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function matchingEpisodes(string $query, int $limit): array
    {
        $like = '%'.$query.'%';
        $fulltext = $this->fullTextClause(['title', 'description'], $query);

        return Episode::query()
            ->where('availability', 'available')
            ->where(function ($builder) use ($like, $fulltext): void {
                if ($fulltext !== null) {
                    $builder->whereRaw($fulltext['match'], $fulltext['bindings']);
                }
                $this->applyLikeFallback($builder, ['title', 'description'], $like);
            })
            ->whereHas('show', fn ($builder) => $builder->where('status', 'active'))
            ->with('show:id,title,artwork_url,status')
            ->when(
                $fulltext !== null,
                fn ($builder) => $builder->orderByRaw($fulltext['rank'], $fulltext['bindings'])
            )
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Episode $episode): array => [
                'id' => $episode->id,
                'title' => $episode->title,
                'show_id' => $episode->show_id,
                'show_title' => $episode->show?->title,
                'artwork_url' => $episode->show?->artwork_url,
                'published_at' => optional($episode->published_at)?->toIso8601String(),
                'duration_seconds' => $episode->duration_seconds,
            ])->values()->all();
    }

    private function matchingPlaylists(string $query, int $limit): array
    {
        $like = '%'.$query.'%';
        $fulltext = $this->fullTextClause(['title', 'description'], $query);
        $playlistsQuery = DB::table('editorial_playlists')
            ->where('published', true)
            ->where(function ($builder) use ($like, $fulltext): void {
                if ($fulltext !== null) {
                    $builder->whereRaw($fulltext['match'], $fulltext['bindings']);
                }
                $this->applyLikeFallback($builder, ['title', 'description'], $like);
            });

        if ($fulltext !== null) {
            $playlistsQuery->orderByRaw($fulltext['rank'], $fulltext['bindings']);
        }

        $playlists = $playlistsQuery->orderBy('position')->orderBy('id')->limit($limit)->get(['id', 'title', 'description', 'artwork_url']);
        $counts = $playlists->isEmpty() ? collect() : DB::table('editorial_playlist_items')->whereIn('editorial_playlist_id', $playlists->pluck('id'))->selectRaw('editorial_playlist_id, count(*) as item_count')->groupBy('editorial_playlist_id')->pluck('item_count', 'editorial_playlist_id');

        return $playlists->map(fn (object $playlist): array => [
            'id' => $playlist->id,
            'title' => $playlist->title,
            'description' => $playlist->description,
            'artwork_url' => $playlist->artwork_url,
            'item_count' => (int) ($counts[$playlist->id] ?? 0),
        ])->values()->all();
    }

    /**
     * @param  list<string>  $columns
     * @return array{match: string, rank: string, bindings: list<string>}|null
     */
    private function fullTextClause(array $columns, string $query): ?array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $boolean = $this->toBooleanFulltextQuery($query);
            if ($boolean === null) {
                return null;
            }
            $columnList = implode(', ', $columns);

            return [
                'match' => "MATCH({$columnList}) AGAINST(? IN BOOLEAN MODE)",
                'rank' => "MATCH({$columnList}) AGAINST(? IN BOOLEAN MODE) DESC",
                'bindings' => [$boolean],
            ];
        }

        if ($driver === 'pgsql') {
            $tsQuery = $this->toPostgresTsQuery($query);
            if ($tsQuery === null) {
                return null;
            }
            $document = collect($columns)
                ->map(fn (string $column): string => "coalesce({$column}, '')")
                ->implode(" || ' ' || ");

            return [
                'match' => "to_tsvector('english', {$document}) @@ to_tsquery('english', ?)",
                'rank' => "ts_rank(to_tsvector('english', {$document}), to_tsquery('english', ?)) DESC",
                'bindings' => [$tsQuery],
            ];
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function applyLikeFallback($builder, array $columns, string $like): void
    {
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        foreach ($columns as $column) {
            $builder->orWhere($column, $operator, $like);
        }
    }

    /**
     * Build a MySQL BOOLEAN MODE query with prefix matching (+term*).
     */
    private function toBooleanFulltextQuery(string $query): ?string
    {
        $parts = $this->searchTerms($query);
        if ($parts === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $term): string => '+'.$term.'*', $parts));
    }

    /**
     * Build a PostgreSQL tsquery with prefix matching (term:* & term:*).
     */
    private function toPostgresTsQuery(string $query): ?string
    {
        $parts = $this->searchTerms($query);
        if ($parts === []) {
            return null;
        }

        return implode(' & ', array_map(fn (string $term): string => $term.':*', $parts));
    }

    /**
     * @return list<string>
     */
    private function searchTerms(string $query): array
    {
        $terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false || $terms === []) {
            return [];
        }

        $parts = [];
        foreach ($terms as $term) {
            $clean = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $term) ?? '';
            $clean = trim($clean);
            if ($clean === '' || mb_strlen($clean) < 2) {
                continue;
            }
            $parts[] = $clean;
        }

        return $parts;
    }

    private function presentShowCard(Show $show): array
    {
        $userId = request()->user()?->id;

        return [
            'id' => $show->id,
            'title' => $show->title,
            'author' => $show->author,
            'artwork_url' => $show->artwork_url,
            'description' => $show->description,
            'following' => $userId !== null && DB::table('follows')->where('user_id', $userId)->where('show_id', $show->id)->exists(),
        ];
    }

    private function presentShowDetail(Show $show, Request $request): array
    {
        $userId = $request->user()?->id;
        $follow = $userId ? DB::table('follows')->where('user_id', $userId)->where('show_id', $show->id)->first() : null;
        $rating = DB::table('show_ratings')->where('show_id', $show->id)->selectRaw('avg(rating) as avg_rating, count(*) as rating_count')->first();
        $categories = DB::table('category_show')->join('categories', 'categories.id', '=', 'category_show.category_id')->where('category_show.show_id', $show->id)->orderBy('categories.name')->get(['categories.name', 'categories.slug']);

        return [
            'id' => $show->id,
            'title' => $show->title,
            'author' => $show->author,
            'artwork_url' => $show->artwork_url,
            'description' => $show->description,
            'language' => $show->language,
            'country_code' => $show->country_code,
            'explicit' => $show->explicit,
            'episodes_count' => $show->episodes()->count(),
            'follower_count' => DB::table('follows')->where('show_id', $show->id)->count(),
            'rating_average' => ($rating && (int) $rating->rating_count > 0) ? number_format((float) $rating->avg_rating, 1, '.', '') : null,
            'rating_count' => (int) ($rating->rating_count ?? 0),
            'following' => $follow !== null,
            'notifications_enabled' => (bool) ($follow->notifications_enabled ?? false),
            'feed_state' => $show->feedState?->state,
            'episodes_syncing' => $this->episodesSyncing($show),
            'feed_error' => $show->feedState?->last_error,
            'claimed_by_viewer' => $userId !== null && DB::table('verified_show_claims')
                ->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')
                ->join('creator_profiles', 'creator_profiles.id', '=', 'show_claims.creator_profile_id')
                ->where('verified_show_claims.show_id', $show->id)
                ->where('creator_profiles.user_id', $userId)
                ->exists(),
            'categories' => $categories->map(fn (object $category): array => [
                'name' => $category->name,
                'slug' => $category->slug,
            ])->values(),
        ];
    }

    private function presentEpisode(Episode $episode, ?Show $show = null, bool $detailed = false): array
    {
        $payload = [
            'id' => $episode->id,
            'show_id' => $episode->show_id,
            'title' => $episode->title,
            'description' => $episode->description,
            'artwork_url' => $episode->show?->artwork_url ?? $show?->artwork_url,
            'duration_seconds' => $episode->duration_seconds,
            'published_at' => optional($episode->published_at)?->toIso8601String(),
            'show_title' => $episode->show?->title ?? $show?->title,
            'show_author' => $episode->show?->author ?? $show?->author,
        ];
        if ($detailed) {
            $payload['audio_url'] = $episode->audio_url;
        }

        return $payload;
    }

    private function episodesSyncing(Show $show): bool
    {
        $state = $show->feedState?->state;

        // Stay "syncing" while the worker is pending/running even after a Podcast
        // Index seed page lands — the detail screen keeps polling until healthy.
        return in_array($state, [null, 'pending', 'running'], true);
    }

    private function ensureRssHydrationQueued(Show $show): void
    {
        $feedState = $show->feedState;
        $state = $feedState?->state;
        $hasEpisodes = $show->episodes()->exists();
        $stuckSince = now()->subMinutes(5);
        $updatedAt = $feedState?->updated_at;
        $isStuck = in_array($state, ['pending', 'running'], true)
            && ($updatedAt === null || $updatedAt->lte($stuckSince));

        // pending = explicit hydrate request (discovery / refresh).
        // Re-queue stuck pending/running so a dead worker can't strand the UI.
        // Empty failed/stale shows retry when the user opens or follows again.
        $needsHydration = $state === 'pending'
            || $isStuck
            || (! $hasEpisodes && ! in_array($state, ['running'], true));

        if (! $needsHydration) {
            return;
        }

        $key = 'show-hydrate:'.$show->id;
        if ($isStuck || (! $hasEpisodes && in_array($state, ['failed', 'stale'], true))) {
            Cache::forget($key);
        }
        if (! Cache::add($key, true, now()->addMinutes(2))) {
            return;
        }

        $show->feedState()->updateOrCreate([], [
            'state' => 'pending',
            'consecutive_failures' => $feedState?->consecutive_failures ?? 0,
            'next_poll_at' => now(),
            'last_error' => $isStuck ? null : $feedState?->last_error,
        ]);

        try {
            $this->dispatchShowHydration($show, preferInline: ! $hasEpisodes);
            Log::info('catalog.show.hydrate_queued', [
                'show_id' => $show->id,
                'feed_state' => $show->feedState?->fresh()?->state,
                'inline' => ! $hasEpisodes,
            ]);
        } catch (\Throwable $exception) {
            Cache::forget($key);
            Log::warning('catalog.show.hydrate_dispatch_failed', [
                'show_id' => $show->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Empty shows hydrate after the HTTP response so local/dev works without a
     * queue worker. Shows that already have episodes stay on the rss queue.
     */
    private function dispatchShowHydration(Show $show, bool $preferInline): void
    {
        if ($preferInline) {
            HydrateRssFeed::dispatchAfterResponse($show->id);

            return;
        }

        HydrateRssFeed::dispatch($show->id);
    }
}
