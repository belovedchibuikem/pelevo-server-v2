<?php

namespace App\Actions\Catalog;

use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Support\ArtworkUrl;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class BuildHomeFeed
{
    public function handle(string $userId): array
    {
        return $this->activeModules()
            ->map(fn (array $module): array => $this->present($module, $this->datasets($userId)[$module['key']] ?? collect(), $userId))
            ->values()
            ->all();
    }

    /**
     * @param  array{min_duration?: int, max_duration?: int, country?: string}  $filters
     * @return array<string, mixed>|null
     */
    public function rail(string $userId, string $key, array $filters = []): ?array
    {
        $module = $this->activeModules()->firstWhere('key', $key);
        if ($module === null) {
            return null;
        }

        return $this->present($module, $this->datasets($userId, $filters)[$key] ?? collect(), $userId);
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function present(array $module, Collection $items, string $userId): array
    {
        $presented = $items->values()->map(fn (object $item, int $index): array => $this->presentItem($item, $module['key'], $index))->all();

        $payload = [
            'id' => 'home.'.$module['key'],
            'key' => $module['key'],
            'type' => $module['kind'],
            'title' => $module['title'],
            'subtitle' => $module['subtitle'],
            'see_all_url' => '/api/v1/home/rails/'.$module['key'],
            'items' => $presented,
        ];

        if ($module['key'] === 'because_you_listened') {
            $payload['inspiration'] = $this->inspirationBanner($userId);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(object $item, string $railKey, int $index): array
    {
        $type = (string) ($item->type ?? 'episode');

        return match ($type) {
            'mood' => [
                'id' => $item->id,
                'type' => 'mood',
                'title' => $item->title,
                'slug' => $item->slug ?? null,
                'subtitle' => $item->subtitle ?? null,
                'icon' => $item->icon ?? null,
                'color' => $item->color ?? null,
            ],
            'category' => [
                'id' => $item->id,
                'type' => 'category',
                'title' => $item->title,
                'slug' => $item->slug ?? null,
            ],
            'browse' => [
                'id' => $item->id,
                'type' => 'browse',
                'title' => $item->title,
                'slug' => $item->slug ?? null,
                'icon' => $item->icon ?? null,
                'color' => $item->color ?? null,
            ],
            'show' => [
                'id' => $item->id,
                'type' => 'show',
                'title' => $item->title,
                'subtitle' => $item->subtitle ?? $item->author ?? null,
                'artwork_url' => ArtworkUrl::sanitize($item->artwork_url ?? null),
                'author' => $item->author ?? null,
                'country_code' => $item->country_code ?? null,
                'rank' => isset($item->rank) ? (int) $item->rank : ($railKey === 'trending' ? $index + 1 : null),
                'play_count' => isset($item->play_count) ? (int) $item->play_count : null,
                'play_count_label' => isset($item->play_count) ? $this->formatCount((int) $item->play_count) : null,
            ],
            'reel' => [
                'id' => $item->id,
                'type' => 'reel',
                'title' => $item->title,
                'subtitle' => $item->subtitle ?? null,
                'artwork_url' => ArtworkUrl::sanitize($item->artwork_url ?? null),
                'badge' => $item->badge ?? null,
                'badge_color' => $item->badge_color ?? null,
                'viewer_count' => isset($item->viewer_count) ? (int) $item->viewer_count : null,
            ],
            default => $this->presentEpisode($item, $railKey, $index),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEpisode(object $item, string $railKey, int $index): array
    {
        $duration = isset($item->duration_seconds) ? (int) $item->duration_seconds : null;
        $match = isset($item->match_percent) ? (int) $item->match_percent : $this->matchPercent($railKey, $index);
        $description = isset($item->description) ? Str::limit(strip_tags((string) $item->description), 120, '') : null;

        return [
            'id' => $item->id,
            'type' => 'episode',
            'title' => $item->title,
            'show_id' => $item->show_id ?? null,
            'show_title' => $item->show_title ?? null,
            'subtitle' => $item->show_title ?? null,
            'description' => $description !== '' ? $description : null,
            'artwork_url' => ArtworkUrl::sanitize($item->artwork_url ?? null),
            'duration_seconds' => $duration,
            'duration_label' => $duration !== null ? $this->formatDuration($duration) : null,
            'position_seconds' => isset($item->position_seconds) ? (int) $item->position_seconds : null,
            'published_at' => $item->published_at ?? null,
            'published_label' => isset($item->published_at) ? $this->relativeTime($item->published_at) : null,
            'match_percent' => $match,
            'reason' => $item->reason ?? $this->pickReason($railKey, $item),
            'country_code' => $item->country_code ?? null,
            'rank' => isset($item->rank) ? (int) $item->rank : null,
        ];
    }

    /**
     * @param  array{min_duration?: int, max_duration?: int, country?: string}  $filters
     * @return array<string, Collection<int, object>>
     */
    private function datasets(string $userId, array $filters = []): array
    {
        $trendingShows = $this->trendingShows();
        $madeForYou = $this->affinityEpisodes($userId, $this->trendingEpisodesFallback());

        return [
            'moods' => $this->moods(),
            'pick_for_today' => $this->withPickReason($userId, $madeForYou->take(1)),
            'continue_listening' => $this->continueListening($userId),
            'made_for_you' => $madeForYou,
            'quick_listen' => $this->quickListen($filters['min_duration'] ?? null, $filters['max_duration'] ?? null),
            'because_you_listened' => $this->affinityEpisodes($userId, $this->trendingEpisodesFallback(), true),
            'trending' => $trendingShows,
            'new_from_following' => $this->following($userId),
            'african_voices' => $this->africanVoiceShows($filters['country'] ?? null),
            'try_something_new' => $this->unexploredCategories($userId),
            'explore_by_topic' => $this->categories(30),
            'trending_shorts' => $this->trendingShorts(),
            'shorts_for_you' => $this->shortsForYou($userId),
            'browse_categories' => $this->browseCategories(),
        ];
    }

    private function continueListening(string $userId): Collection
    {
        return $this->episodes()
            ->join('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->where('playback_progress.user_id', $userId)
            ->where('playback_progress.completed', false)
            ->where('playback_progress.position_seconds', '>', 0)
            ->addSelect('playback_progress.position_seconds', 'playback_progress.version')
            ->orderByDesc('playback_progress.updated_at')
            ->limit(20)
            ->get();
    }

    private function following(string $userId): Collection
    {
        return $this->episodes()
            ->join('follows', 'follows.show_id', '=', 'shows.id')
            ->where('follows.user_id', $userId)
            ->orderByDesc('episodes.published_at')
            ->orderByDesc('episodes.id')
            ->limit(30)
            ->get();
    }

    private function quickListen(?int $minDuration = null, ?int $maxDuration = null): Collection
    {
        $minimum = $minDuration ?? 300;
        $query = $this->episodes()->where('episodes.duration_seconds', '>=', $minimum);
        if ($maxDuration !== null) {
            $query->where('episodes.duration_seconds', '<=', $maxDuration);
        } elseif ($minDuration === null) {
            $query->where('episodes.duration_seconds', '<=', 1200);
        }

        return $query
            ->orderByDesc('episodes.published_at')
            ->orderByDesc('episodes.id')
            ->limit(30)
            ->get();
    }

    private function africanVoiceShows(?string $country = null): Collection
    {
        $countries = ['NG', 'GH', 'KE', 'ZA', 'UG', 'TZ', 'RW', 'ET', 'CM', 'SN', 'CI'];
        $selected = is_string($country) ? strtoupper($country) : null;
        $targetCountries = $selected !== null && in_array($selected, $countries, true) ? [$selected] : $countries;

        $shows = $this->shows()
            ->whereIn('shows.country_code', $targetCountries)
            ->orderByDesc('shows.updated_at')
            ->orderByDesc('shows.id')
            ->limit(30)
            ->get();

        if ($shows->isNotEmpty()) {
            return $shows;
        }

        $query = match ($selected) {
            'NG' => 'nigeria podcast',
            'GH' => 'ghana podcast',
            'KE' => 'kenya podcast',
            default => 'africa podcast',
        };

        return $this->discoverShows($query, 12, $selected);
    }

    private function trendingShows(): Collection
    {
        $shows = DB::table('shows')
            ->leftJoin('episodes', function ($join): void {
                $join->on('episodes.show_id', '=', 'shows.id')
                    ->where('episodes.availability', 'available');
            })
            ->leftJoin('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->where('shows.status', 'active')
            ->select(
                'shows.id',
                DB::raw("'show' AS type"),
                'shows.title',
                'shows.author as subtitle',
                'shows.artwork_url',
                'shows.author',
                'shows.country_code',
                DB::raw('COUNT(DISTINCT playback_progress.user_id) AS play_count'),
            )
            ->groupBy('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url', 'shows.country_code')
            ->orderByDesc('play_count')
            ->orderByDesc('shows.updated_at')
            ->limit(30)
            ->get()
            ->values()
            ->map(function (object $show, int $index): object {
                $show->rank = $index + 1;

                return $show;
            });

        if ($shows->filter(fn (object $show): bool => (int) ($show->play_count ?? 0) > 0)->isNotEmpty()) {
            return $shows;
        }

        if ($shows->isNotEmpty()) {
            return $shows;
        }

        return $this->discoverShows('africa podcast nigeria', 12);
    }

    private function trendingEpisodesFallback(): Collection
    {
        return $this->episodes()
            ->leftJoin('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->leftJoin('episode_saves', 'episode_saves.episode_id', '=', 'episodes.id')
            ->addSelect(DB::raw('COUNT(DISTINCT playback_progress.user_id) AS listener_count'), DB::raw('COUNT(DISTINCT episode_saves.user_id) AS save_count'))
            ->groupBy('episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.description', 'episodes.audio_url', 'episodes.duration_seconds', 'episodes.published_at', 'shows.title', 'shows.artwork_url', 'shows.author', 'shows.country_code')
            ->orderByDesc('listener_count')
            ->orderByDesc('save_count')
            ->orderByDesc('episodes.published_at')
            ->limit(30)
            ->get();
    }

    private function affinityEpisodes(string $userId, Collection $fallback, bool $excludePlayed = false): Collection
    {
        $playedEpisodeIds = DB::table('playback_progress')->where('user_id', $userId)->pluck('episode_id');
        $categoryIds = DB::table('category_show')
            ->join('episodes', 'episodes.show_id', '=', 'category_show.show_id')
            ->join('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->where('playback_progress.user_id', $userId)
            ->distinct()
            ->pluck('category_show.category_id');
        if ($categoryIds->isEmpty()) {
            return $fallback->take(20)->values();
        }

        return $this->episodes()
            ->join('category_show', 'category_show.show_id', '=', 'shows.id')
            ->whereIn('category_show.category_id', $categoryIds)
            ->when($excludePlayed && $playedEpisodeIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('episodes.id', $playedEpisodeIds))
            ->distinct()
            ->orderByDesc('episodes.published_at')
            ->orderByDesc('episodes.id')
            ->limit(20)
            ->get();
    }

    private function withPickReason(string $userId, Collection $items): Collection
    {
        $completed = DB::table('playback_progress')
            ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
            ->where('playback_progress.user_id', $userId)
            ->where('playback_progress.completed', true)
            ->orderByDesc('playback_progress.updated_at')
            ->value('episodes.title');

        return $items->map(function (object $item) use ($completed): object {
            $item->reason = $completed
                ? 'Picked because you finished '.$completed
                : 'Picked for you based on what you like on Pelevo';

            return $item;
        });
    }

    /**
     * @return array{title: string, subtitle: string}
     */
    private function inspirationBanner(string $userId): array
    {
        $showTitle = DB::table('playback_progress')
            ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('playback_progress.user_id', $userId)
            ->orderByDesc('playback_progress.updated_at')
            ->value('shows.title');

        if (is_string($showTitle) && $showTitle !== '') {
            return [
                'title' => 'Inspired by '.$showTitle,
                'subtitle' => 'More thoughtful conversations about growth',
            ];
        }

        return [
            'title' => 'Inspired by your listening',
            'subtitle' => 'More shows and episodes in a similar lane',
        ];
    }

    private function unexploredCategories(string $userId): Collection
    {
        $explored = DB::table('category_show')
            ->join('episodes', 'episodes.show_id', '=', 'category_show.show_id')
            ->join('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->where('playback_progress.user_id', $userId)
            ->distinct()
            ->pluck('category_show.category_id');

        $categories = $this->categoryQuery()
            ->when($explored->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('categories.id', $explored))
            ->limit(8)
            ->get();

        return $categories->isNotEmpty() ? $categories : $this->categoryQuery()->limit(8)->get();
    }

    private function categories(int $limit): Collection
    {
        return $this->categoryQuery()->limit($limit)->get();
    }

    private function categoryQuery(): Builder
    {
        return DB::table('categories')
            ->where('active', true)
            ->select('categories.id', DB::raw("'category' AS type"), 'categories.name as title', 'categories.slug')
            ->orderBy('categories.position')
            ->orderBy('categories.id');
    }

    private function moods(): Collection
    {
        return collect([
            (object) ['id' => 'mood-learn', 'type' => 'mood', 'title' => 'Learn Something', 'slug' => 'education', 'icon' => 'menu_book', 'color' => '#1B4B4A'],
            (object) ['id' => 'mood-money', 'type' => 'mood', 'title' => 'Money & Business', 'slug' => 'business', 'icon' => 'payments', 'color' => '#3A3218'],
            (object) ['id' => 'mood-feel', 'type' => 'mood', 'title' => 'Feel Something', 'slug' => 'society', 'icon' => 'favorite', 'color' => '#4A1D2A'],
            (object) ['id' => 'mood-faith', 'type' => 'mood', 'title' => 'Faith & Purpose', 'slug' => 'faith', 'icon' => 'auto_awesome', 'color' => '#2A2150'],
            (object) ['id' => 'mood-entertain', 'type' => 'mood', 'title' => 'Be Entertained', 'slug' => 'comedy', 'icon' => 'celebration', 'color' => '#3A2748'],
            (object) ['id' => 'mood-sleep', 'type' => 'mood', 'title' => 'Sleep & Unwind', 'slug' => 'health', 'icon' => 'nights_stay', 'color' => '#1A2748'],
        ]);
    }

    private function browseCategories(): Collection
    {
        return collect([
            (object) ['id' => 'browse-podcasts', 'type' => 'browse', 'title' => 'Podcasts', 'slug' => 'podcasts', 'icon' => 'podcasts', 'color' => '#ED1C2B'],
            (object) ['id' => 'browse-shorts', 'type' => 'browse', 'title' => 'Shorts', 'slug' => 'shorts', 'icon' => 'smart_display', 'color' => '#9B5CFF'],
            (object) ['id' => 'browse-audiobooks', 'type' => 'browse', 'title' => 'Audiobooks', 'slug' => 'audiobooks', 'icon' => 'headphones', 'color' => '#2FA44F'],
            (object) ['id' => 'browse-more', 'type' => 'browse', 'title' => 'More', 'slug' => 'more', 'icon' => 'grid_view', 'color' => '#191B1A'],
        ]);
    }

    private function trendingShorts(): Collection
    {
        $rows = $this->reelsQuery()
            ->leftJoin('reel_view_credits', 'reel_view_credits.reel_id', '=', 'reels.id')
            ->select(
                'reels.id',
                'reels.caption',
                'reels.published_at',
                'shows.title as show_title',
                'shows.artwork_url',
                'shows.author',
                'creator_profiles.display_name',
                DB::raw('COUNT(reel_view_credits.reel_view_id) AS viewer_count'),
            )
            ->groupBy(
                'reels.id',
                'reels.caption',
                'reels.published_at',
                'shows.title',
                'shows.artwork_url',
                'shows.author',
                'creator_profiles.display_name',
            )
            ->orderByDesc('viewer_count')
            ->orderByDesc('reels.published_at')
            ->limit(20)
            ->get();

        return $this->presentReelRows($rows, trending: true);
    }

    private function shortsForYou(string $userId): Collection
    {
        $rows = $this->reelsQuery()
            ->leftJoin('follows', function ($join) use ($userId): void {
                $join->on('follows.show_id', '=', 'reels.show_id')->where('follows.user_id', $userId);
            })
            ->select(
                'reels.id',
                'reels.caption',
                'reels.published_at',
                'shows.title as show_title',
                'shows.artwork_url',
                'shows.author',
                'creator_profiles.display_name',
                DB::raw('CASE WHEN follows.user_id IS NULL THEN 0 ELSE 1 END AS followed'),
            )
            ->orderByDesc('followed')
            ->orderByDesc('reels.published_at')
            ->limit(20)
            ->get();

        $items = $this->presentReelRows($rows, trending: false);

        return $items->isNotEmpty() ? $items : $this->trendingShorts();
    }

    private function presentReelRows(Collection $rows, bool $trending): Collection
    {
        $thumbnails = DB::table('reel_media')
            ->whereIn('reel_id', $rows->pluck('id'))
            ->orderByDesc('created_at')
            ->get(['reel_id', 'thumbnail_path'])
            ->unique('reel_id')
            ->keyBy('reel_id');

        return $rows->values()->map(function (object $row, int $index) use ($thumbnails, $trending): object {
            $caption = trim((string) ($row->caption ?? ''));
            $count = (int) ($row->viewer_count ?? 0);

            return (object) [
                'id' => $row->id,
                'type' => 'reel',
                'title' => $caption !== '' ? $caption : (string) ($row->show_title ?: 'Short'),
                'subtitle' => $row->display_name ?? $row->author ?? 'Pelevo',
                'artwork_url' => ArtworkUrl::sanitize($row->artwork_url ?? $thumbnails->get($row->id)?->thumbnail_path),
                'viewer_count' => $count,
                'badge' => $trending
                    ? ($count > 0 ? $this->formatCount($count).' plays' : 'Trending')
                    : match ($index) {
                        0 => 'For you',
                        1 => '98% match',
                        default => 'New for you',
                    },
                'badge_color' => $trending
                    ? ['#9B5CFF', '#ED1C2B', '#2FA44F'][$index % 3]
                    : ['#9B5CFF', '#2FA44F', '#FFBE16'][$index % 3],
            ];
        });
    }

    private function reelsQuery(): Builder
    {
        return DB::table('reels')
            ->leftJoin('shows', 'shows.id', '=', 'reels.show_id')
            ->join('creator_profiles', 'creator_profiles.id', '=', 'reels.creator_profile_id')
            ->where('reels.state', 'published');
    }

    private function shows(): Builder
    {
        return DB::table('shows')
            ->where('shows.status', 'active')
            ->select(
                'shows.id',
                DB::raw("'show' AS type"),
                'shows.title',
                'shows.author as subtitle',
                'shows.artwork_url',
                'shows.author',
                'shows.country_code',
            );
    }

    private function episodes(): Builder
    {
        return DB::table('episodes')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('episodes.availability', 'available')
            ->where('shows.status', 'active')
            ->select(
                'episodes.id',
                'episodes.show_id',
                DB::raw("'episode' AS type"),
                'episodes.title',
                'episodes.description',
                'episodes.audio_url',
                'episodes.duration_seconds',
                'episodes.published_at',
                'shows.title as show_title',
                'shows.artwork_url',
                'shows.author',
                'shows.country_code',
            );
    }

    private function discoverShows(string $query, int $limit = 12, ?string $country = null): Collection
    {
        if (! config('services.podcast_index.enabled')) {
            return collect();
        }

        try {
            $client = app(PodcastIndexClient::class);
            $persist = app(PersistDiscoveredShow::class);
            $feeds = $client->searchByTerm($query, $limit)['feeds'] ?? [];
            $ids = [];
            foreach ($feeds as $feed) {
                if (! is_array($feed)) {
                    continue;
                }
                if ($country !== null && isset($feed['country']) && strtoupper((string) $feed['country']) !== $country) {
                    // Still persist; country filter is soft for discovery cold-start.
                }
                if ($show = $persist->handle($feed)) {
                    if ($country !== null && $show->country_code === null) {
                        $show->forceFill(['country_code' => $country])->save();
                    }
                    $ids[] = $show->id;
                }
            }

            if ($ids === []) {
                return collect();
            }

            Log::info('home.discover.podcast_index', ['query' => $query, 'persisted' => count($ids)]);

            return $this->shows()->whereIn('shows.id', $ids)->limit($limit)->get();
        } catch (PodcastIndexException $exception) {
            Log::warning('home.discover.podcast_index_failed', [
                'query' => $query,
                'message' => $exception->getMessage(),
            ]);

            return collect();
        }
    }

    private function activeModules(): Collection
    {
        $modules = DB::table('home_modules')
            ->where('active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('position')
            ->orderBy('id')
            ->get(['key', 'title', 'subtitle', 'kind'])
            ->map(fn (object $module): array => (array) $module);

        return $modules->isNotEmpty() ? $modules : collect($this->defaultModules());
    }

    private function defaultModules(): array
    {
        return [
            ['key' => 'moods', 'title' => 'What are you in the mood for?', 'subtitle' => 'Browse topics that match this moment', 'kind' => 'moods'],
            ['key' => 'pick_for_today', 'title' => 'Your Pick for Today', 'subtitle' => 'One episode selected from Pelevo recommendations', 'kind' => 'hero_episode'],
            ['key' => 'continue_listening', 'title' => 'Continue Listening', 'subtitle' => 'Pick up where you left off', 'kind' => 'episode_progress'],
            ['key' => 'made_for_you', 'title' => 'Made For You', 'subtitle' => 'Personalized episode picks', 'kind' => 'episode_list'],
            ['key' => 'quick_listen', 'title' => 'Quick Listen', 'subtitle' => 'Short episodes that fit your time', 'kind' => 'quick_listen'],
            ['key' => 'because_you_listened', 'title' => 'Because You Listened', 'subtitle' => 'More from topics you already enjoy', 'kind' => 'because'],
            ['key' => 'trending', 'title' => 'Trending on Pelevo', 'subtitle' => 'What listeners are playing now', 'kind' => 'ranked_shows'],
            ['key' => 'new_from_following', 'title' => 'New From Shows You Follow', 'subtitle' => 'Fresh episodes from your subscriptions', 'kind' => 'episode_list'],
            ['key' => 'african_voices', 'title' => 'African Voices', 'subtitle' => 'Podcasts from across the continent', 'kind' => 'shows'],
            ['key' => 'try_something_new', 'title' => 'Try Something New', 'subtitle' => 'Topics outside your listening history', 'kind' => 'try_new'],
            ['key' => 'explore_by_topic', 'title' => 'Explore by Topic', 'subtitle' => 'Browse the Pelevo catalog', 'kind' => 'topics'],
            ['key' => 'trending_shorts', 'title' => 'Trending Shorts', 'subtitle' => 'Shorts gaining momentum', 'kind' => 'shorts'],
            ['key' => 'shorts_for_you', 'title' => 'Shorts You Might Like', 'subtitle' => 'Personalized short picks', 'kind' => 'shorts'],
            ['key' => 'browse_categories', 'title' => 'Browse Categories', 'subtitle' => 'Jump into a format', 'kind' => 'browse'],
        ];
    }

    private function matchPercent(string $railKey, int $index): ?int
    {
        if (! in_array($railKey, ['pick_for_today', 'made_for_you', 'because_you_listened'], true)) {
            return null;
        }

        return max(72, 98 - ($index * 3));
    }

    private function pickReason(string $railKey, object $item): ?string
    {
        if ($railKey !== 'pick_for_today') {
            return null;
        }

        return $item->reason ?? null;
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = max(1, (int) round($seconds / 60));

        return $minutes.' min';
    }

    private function formatCount(int $count): string
    {
        if ($count >= 1000000) {
            return rtrim(rtrim(number_format($count / 1000000, 1), '0'), '.').'M';
        }
        if ($count >= 1000) {
            return rtrim(rtrim(number_format($count / 1000, 1), '0'), '.').'K';
        }

        return (string) $count;
    }

    private function relativeTime(mixed $value): string
    {
        try {
            $time = $value instanceof CarbonInterface
                ? $value
                : \Illuminate\Support\Carbon::parse((string) $value);

            return $time->diffForHumans();
        } catch (\Throwable) {
            return '';
        }
    }
}
