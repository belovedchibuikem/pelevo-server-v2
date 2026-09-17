<?php

namespace App\Actions\Catalog;

use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Support\ArtworkUrl;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class BuildHomeFeed
{
    /** @var array{title: string, subtitle: string}|null */
    private ?array $becauseInspiration = null;

    public function handle(string $userId): array
    {
        $datasets = $this->datasets($userId);

        return $this->activeModules()
            ->map(fn (array $module): array => $this->present($module, $datasets[$module['key']] ?? collect(), $userId))
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

        return $this->present($module, $this->datasets($userId, $filters, $key)[$key] ?? collect(), $userId);
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
                'id' => (string) $item->id,
                'type' => 'mood',
                'title' => $item->title,
                'slug' => $item->slug ?? null,
                'subtitle' => $item->subtitle ?? null,
                'icon' => $item->icon ?? null,
                'color' => $item->color ?? null,
            ],
            'category' => [
                'id' => (string) $item->id,
                'type' => 'category',
                'title' => $item->title,
                'slug' => $item->slug ?? null,
            ],
            'browse' => [
                'id' => (string) $item->id,
                'type' => 'browse',
                'title' => $item->title,
                'slug' => $item->slug ?? null,
                'icon' => $item->icon ?? null,
                'color' => $item->color ?? null,
            ],
            'show' => [
                'id' => (string) $item->id,
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
                'id' => (string) $item->id,
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
            'id' => (string) $item->id,
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
    private function datasets(string $userId, array $filters = [], ?string $only = null): array
    {
        if ($only !== null) {
            return [$only => $this->dataset($userId, $only, $filters)];
        }

        $trendingEpisodes = $this->trendingEpisodesFallback();
        $madeForYou = $this->affinityEpisodes($userId, collect());

        return [
            'moods' => $this->moods(),
            'pick_for_today' => $this->pickForToday($userId, $madeForYou, $trendingEpisodes),
            'continue_listening' => $this->continueListening($userId),
            'made_for_you' => $madeForYou,
            'quick_listen' => $this->quickListen($userId, $filters['min_duration'] ?? null, $filters['max_duration'] ?? null),
            'because_you_listened' => $this->becauseYouListened($userId, collect()),
            'trending' => $this->historyShows($userId),
            'new_from_following' => $this->following($userId),
            'african_voices' => $this->africanVoiceShows($userId, $filters['country'] ?? null),
            'try_something_new' => collect(),
            'explore_by_topic' => $this->historyTopics($userId),
            'trending_shorts' => collect(),
            'shorts_for_you' => $this->shortsForYou($userId),
            'browse_categories' => $this->browseCategories(),
        ];
    }

    /**
     * @param  array{min_duration?: int, max_duration?: int, country?: string}  $filters
     */
    private function dataset(string $userId, string $key, array $filters): Collection
    {
        return match ($key) {
            'moods' => $this->moods(),
            'continue_listening' => $this->continueListening($userId),
            'quick_listen' => $this->quickListen($userId, $filters['min_duration'] ?? null, $filters['max_duration'] ?? null),
            'trending' => $this->historyShows($userId),
            'new_from_following' => $this->following($userId),
            'african_voices' => $this->africanVoiceShows($userId, $filters['country'] ?? null),
            'try_something_new' => collect(),
            'explore_by_topic' => $this->historyTopics($userId),
            'trending_shorts' => collect(),
            'shorts_for_you' => $this->shortsForYou($userId),
            'browse_categories' => $this->browseCategories(),
            'pick_for_today', 'made_for_you', 'because_you_listened' => $this->personalizedDataset($userId, $key),
            default => collect(),
        };
    }

    private function personalizedDataset(string $userId, string $key): Collection
    {
        $madeForYou = $this->affinityEpisodes($userId, collect());

        return match ($key) {
            'pick_for_today' => $this->pickForToday($userId, $madeForYou, $this->trendingEpisodesFallback()),
            'because_you_listened' => $this->becauseYouListened($userId, collect()),
            default => $madeForYou,
        };
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

    private function quickListen(string $userId, ?int $minDuration = null, ?int $maxDuration = null): Collection
    {
        $showIds = $this->listenedShowIds($userId);
        if ($showIds === []) {
            return collect();
        }

        $minimum = $minDuration ?? 300;
        $query = $this->episodes()
            ->whereIn('episodes.show_id', $showIds)
            ->where('episodes.duration_seconds', '>=', $minimum);
        if ($maxDuration !== null) {
            $query->where('episodes.duration_seconds', '<=', $maxDuration);
        } elseif ($minDuration === null) {
            $query->where('episodes.duration_seconds', '<=', 1200);
        }

        $pool = $query
            ->orderByDesc('episodes.published_at')
            ->orderByDesc('episodes.id')
            ->limit(80)
            ->get();

        return $this->rotateCollection($pool, $userId, 'quick_listen:'.$minimum.':'.($maxDuration ?? 'open'), 20);
    }

    private function africanVoiceShows(string $userId, ?string $country = null): Collection
    {
        $countries = ['NG', 'GH', 'KE', 'ZA', 'UG', 'TZ', 'RW', 'ET', 'CM', 'SN', 'CI'];
        $selected = is_string($country) ? strtoupper($country) : null;
        $targetCountries = $selected !== null && in_array($selected, $countries, true) ? [$selected] : $countries;

        $listenedShowIds = $this->listenedShowIds($userId);
        if ($listenedShowIds === []) {
            return collect();
        }

        $shows = $this->shows()
            ->whereIn('shows.id', $listenedShowIds)
            ->whereIn('shows.country_code', $targetCountries)
            ->orderByDesc('shows.updated_at')
            ->orderByDesc('shows.id')
            ->limit(80)
            ->get();

        if ($shows->isEmpty()) {
            return collect();
        }

        $withArtwork = $shows->filter(fn (object $show): bool => ArtworkUrl::sanitize($show->artwork_url ?? null) !== null)->values();
        $withoutArtwork = $shows->filter(fn (object $show): bool => ArtworkUrl::sanitize($show->artwork_url ?? null) === null)->values();
        $rotatedCovers = $this->rotateCollection($withArtwork, $userId, 'african_voices:'.($selected ?? 'all'), 16);
        if ($rotatedCovers->count() >= 8 || $withoutArtwork->isEmpty()) {
            return $rotatedCovers;
        }

        return $rotatedCovers
            ->concat($this->rotateCollection($withoutArtwork, $userId, 'african_voices:plain:'.($selected ?? 'all'), 16 - $rotatedCovers->count()))
            ->values();
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

        return collect();
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

    private function becauseYouListened(string $userId, Collection $fallback): Collection
    {
        $profile = $this->listeningProfile($userId);
        $this->becauseInspiration = [
            'title' => $profile['seed_show_title'] !== null
                ? 'Because You Listened to '.$profile['seed_show_title']
                : 'Because You Listened',
            'subtitle' => $profile['seed_topics'] !== []
                ? 'Related episodes from other podcasts about '.implode(', ', $profile['seed_topics'])
                : 'Similar episodes from other podcasts you have not finished',
        ];

        if ($profile['source_show_ids'] === []) {
            return collect();
        }

        $candidates = $this->episodes()
            ->when($profile['listened_episode_ids'] !== [], fn (Builder $query) => $query->whereNotIn('episodes.id', $profile['listened_episode_ids']))
            ->when($profile['source_show_ids'] !== [], fn (Builder $query) => $query->whereNotIn('episodes.show_id', $profile['source_show_ids']))
            ->when($profile['category_ids'] !== [], function (Builder $query) use ($profile): void {
                $query->whereExists(function (Builder $exists) use ($profile): void {
                    $exists->selectRaw('1')
                        ->from('category_show')
                        ->whereColumn('category_show.show_id', 'shows.id')
                        ->whereIn('category_show.category_id', $profile['category_ids']);
                });
            })
            ->orderByDesc('episodes.published_at')
            ->orderByDesc('episodes.id')
            ->limit(150)
            ->get();

        if ($candidates->isEmpty() && $profile['tokens'] !== []) {
            $candidates = $this->episodes()
                ->when($profile['listened_episode_ids'] !== [], fn (Builder $query) => $query->whereNotIn('episodes.id', $profile['listened_episode_ids']))
                ->when($profile['source_show_ids'] !== [], fn (Builder $query) => $query->whereNotIn('episodes.show_id', $profile['source_show_ids']))
                ->where(function (Builder $query) use ($profile): void {
                    foreach ($profile['tokens'] as $token) {
                        $like = '%'.$token.'%';
                        $query->orWhere('episodes.title', 'like', $like)
                            ->orWhere('episodes.description', 'like', $like)
                            ->orWhere('shows.title', 'like', $like)
                            ->orWhere('shows.description', 'like', $like);
                    }
                })
                ->orderByDesc('episodes.published_at')
                ->limit(150)
                ->get();
        }

        $ranked = $candidates
            ->map(function (object $item) use ($profile): object {
                $haystack = strtolower(trim(($item->title ?? '').' '.($item->description ?? '').' '.($item->show_title ?? '')));
                $tokenHits = 0;
                foreach ($profile['tokens'] as $token) {
                    if ($token !== '' && str_contains($haystack, $token)) {
                        $tokenHits++;
                    }
                }

                $item->recommendation_score = ($profile['category_ids'] !== [] ? 40 : 0) + ($tokenHits * 14);
                $item->reason = $profile['seed_show_title'] !== null
                    ? 'Because you listened to '.$profile['seed_show_title']
                    : 'Because of your recent listening';

                return $item;
            })
            ->sortByDesc(fn (object $item): int => (int) $item->recommendation_score)
            ->unique('show_id')
            ->take(20)
            ->values();

        if ($ranked->isNotEmpty()) {
            return $ranked;
        }

        return collect();
    }

    /**
     * @return array{
     *     source_show_ids: list<string>,
     *     category_ids: list<int|string>,
     *     listened_episode_ids: list<string>,
     *     tokens: list<string>,
     *     seed_show_title: string|null,
     *     seed_topics: list<string>
     * }
     */
    private function listeningProfile(string $userId): array
    {
        $plays = DB::table('playback_progress')
            ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('playback_progress.user_id', $userId)
            ->orderByDesc('playback_progress.updated_at')
            ->get([
                'episodes.id as episode_id',
                'episodes.show_id',
                'episodes.title as episode_title',
                'episodes.description as episode_description',
                'episodes.duration_seconds',
                'shows.title as show_title',
                'playback_progress.completed',
                'playback_progress.position_seconds',
                'playback_progress.updated_at',
            ]);

        $showScores = [];
        $showTitles = [];
        $texts = [];
        foreach ($plays as $play) {
            $showId = (string) $play->show_id;
            $playedAt = \Illuminate\Support\Carbon::parse((string) $play->updated_at)->timestamp;
            $days = max(0, (int) floor((now()->timestamp - $playedAt) / 86400));
            $recency = (int) round(55 * max(0, 1 - ($days / 21)));
            $completed = ((int) $play->completed) === 1 ? 32 : 0;
            $duration = max(1, (int) ($play->duration_seconds ?: 1));
            $depth = (int) min(18, round(((int) $play->position_seconds / $duration) * 18));
            $showScores[$showId] = ($showScores[$showId] ?? 0) + $recency + $completed + $depth + 12;
            $showTitles[$showId] = (string) $play->show_title;
            $texts[] = (string) $play->episode_title;
            $texts[] = (string) $play->episode_description;
            $texts[] = (string) $play->show_title;
        }

        foreach (DB::table('follows')->where('user_id', $userId)->pluck('show_id') as $showId) {
            $showScores[(string) $showId] = ($showScores[(string) $showId] ?? 0) + 40;
        }

        $savedShowIds = DB::table('episode_saves')
            ->join('episodes', 'episodes.id', '=', 'episode_saves.episode_id')
            ->where('episode_saves.user_id', $userId)
            ->pluck('episodes.show_id');
        foreach ($savedShowIds as $showId) {
            $showScores[(string) $showId] = ($showScores[(string) $showId] ?? 0) + 36;
        }

        $shares = DB::table('share_cards')
            ->where('user_id', $userId)
            ->whereIn('subject_type', ['episode', 'show'])
            ->get(['subject_type', 'subject_id']);
        $sharedEpisodeIds = $shares->where('subject_type', 'episode')->pluck('subject_id');
        $sharedShowIds = $shares->where('subject_type', 'show')->pluck('subject_id')->map(fn (mixed $id): string => (string) $id);
        if ($sharedEpisodeIds->isNotEmpty()) {
            $sharedShowIds = $sharedShowIds->merge(
                DB::table('episodes')->whereIn('id', $sharedEpisodeIds)->pluck('show_id')->map(fn (mixed $id): string => (string) $id)
            );
        }
        foreach ($sharedShowIds as $showId) {
            $showScores[(string) $showId] = ($showScores[(string) $showId] ?? 0) + 42;
        }

        arsort($showScores);
        $sourceShowIds = array_keys($showScores);
        foreach ($sourceShowIds as $showId) {
            if (! isset($showTitles[$showId])) {
                $title = DB::table('shows')->where('id', $showId)->value('title');
                if (is_string($title) && $title !== '') {
                    $showTitles[$showId] = $title;
                    $texts[] = $title;
                }
            }
        }

        $categoryRows = $sourceShowIds === []
            ? collect()
            : DB::table('category_show')
                ->join('categories', 'categories.id', '=', 'category_show.category_id')
                ->whereIn('category_show.show_id', $sourceShowIds)
                ->where('categories.active', true)
                ->orderBy('categories.position')
                ->get(['categories.id', 'categories.name', 'category_show.show_id']);

        $seedShowId = $sourceShowIds[0] ?? null;
        $seedCategoryRows = $seedShowId !== null
            ? $categoryRows->where('show_id', $seedShowId)
            : collect();
        $seedTopics = ($seedCategoryRows->isNotEmpty() ? $seedCategoryRows : $categoryRows)
            ->pluck('name')
            ->unique()
            ->take(3)
            ->values()
            ->all();

        return [
            'source_show_ids' => $sourceShowIds,
            'category_ids' => $categoryRows->pluck('id')->unique()->values()->all(),
            'listened_episode_ids' => $plays->pluck('episode_id')->unique()->values()->all(),
            'tokens' => $this->interestTokens($texts),
            'seed_show_title' => $seedShowId !== null ? ($showTitles[$seedShowId] ?? null) : null,
            'seed_topics' => array_values($seedTopics),
        ];
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function interestTokens(array $texts): array
    {
        $stop = [
            'this', 'that', 'with', 'from', 'your', 'about', 'into', 'have', 'been', 'will',
            'podcast', 'episode', 'show', 'audio', 'interview', 'talk', 'conversation',
        ];
        $counts = [];
        foreach ($texts as $text) {
            if ($text === '') {
                continue;
            }
            preg_match_all('/[a-zA-Z]{4,}/', strtolower($text), $matches);
            foreach ($matches[0] as $word) {
                if (in_array($word, $stop, true)) {
                    continue;
                }
                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_slice(array_keys($counts), 0, 8);
    }

    /**
     * @return list<string>
     */
    private function listenedShowIds(string $userId): array
    {
        return DB::table('playback_progress')
            ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
            ->where('playback_progress.user_id', $userId)
            ->distinct()
            ->pluck('episodes.show_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    private function historyShows(string $userId): Collection
    {
        $showIds = $this->listenedShowIds($userId);
        if ($showIds === []) {
            return collect();
        }

        return $this->shows()
            ->whereIn('shows.id', $showIds)
            ->orderByDesc('shows.updated_at')
            ->limit(20)
            ->get();
    }

    private function historyTopics(string $userId): Collection
    {
        $showIds = $this->listenedShowIds($userId);
        if ($showIds === []) {
            return collect();
        }

        return $this->categoryQuery()
            ->join('category_show', 'category_show.category_id', '=', 'categories.id')
            ->whereIn('category_show.show_id', $showIds)
            ->addSelect('categories.position')
            ->groupBy('categories.id', 'categories.name', 'categories.slug', 'categories.position')
            ->limit(30)
            ->get();
    }

    private function rotateCollection(Collection $items, string $userId, string $railKey, int $limit): Collection
    {
        $seed = $userId.'|'.$railKey.'|'.now()->format('Y-m-d').'|'.intdiv((int) now()->format('G'), 4);

        return $items
            ->values()
            ->sortBy(fn (object $item): string => sprintf('%010u', crc32($seed.'|'.$item->id)))
            ->take($limit)
            ->values();
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
            return collect();
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

    private function pickForToday(string $userId, Collection $madeForYou, Collection $trendingEpisodes): Collection
    {
        $hasHistory = DB::table('playback_progress')->where('user_id', $userId)->exists();
        $completedIds = DB::table('playback_progress')
            ->where('user_id', $userId)
            ->where('completed', true)
            ->pluck('episode_id');

        $source = $hasHistory ? $madeForYou : $trendingEpisodes;
        $pool = $source
            ->reject(fn (object $item): bool => $completedIds->contains($item->id))
            ->values();

        if ($pool->isEmpty()) {
            return collect();
        }

        $pick = $this->stickyDailyPick($userId, $pool);
        if ($pick === null) {
            return collect();
        }

        return $this->withPickReason($userId, collect([$pick]), $hasHistory);
    }

    private function stickyDailyPick(string $userId, Collection $pool): ?object
    {
        return DB::transaction(function () use ($userId, $pool): ?object {
            $existing = DB::table('home_daily_picks')->where('user_id', $userId)->lockForUpdate()->first();
            $byId = $pool->keyBy(fn (object $item): string => (string) $item->id);
            $current = $existing ? $byId->get((string) $existing->episode_id) : null;
            $stillValid = $current !== null
                && $existing->consumed_at === null
                && $existing->expires_at !== null
                && now()->lt(\Illuminate\Support\Carbon::parse((string) $existing->expires_at));

            if ($stillValid) {
                return $current;
            }

            $excludeId = $existing->episode_id ?? null;
            $candidates = $pool
                ->reject(fn (object $item): bool => $excludeId !== null && (string) $item->id === (string) $excludeId)
                ->values();
            if ($candidates->isEmpty()) {
                $candidates = $pool->values();
            }

            $latest = $candidates
                ->sortByDesc(fn (object $item): string => sprintf('%s|%s', (string) ($item->published_at ?? ''), $item->id))
                ->values()
                ->take(8);
            if ($latest->isEmpty()) {
                return null;
            }

            $pick = $latest->get(random_int(0, $latest->count() - 1));
            $now = now();
            DB::table('home_daily_picks')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'episode_id' => $pick->id,
                    'selected_at' => $now,
                    'expires_at' => $now->copy()->addHours(6),
                    'consumed_at' => null,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );

            return $pick;
        });
    }

    private function withPickReason(string $userId, Collection $items, bool $hasHistory = true): Collection
    {
        $completed = $hasHistory
            ? DB::table('playback_progress')
                ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
                ->where('playback_progress.user_id', $userId)
                ->where('playback_progress.completed', true)
                ->orderByDesc('playback_progress.updated_at')
                ->value('episodes.title')
            : null;

        return $items->map(function (object $item) use ($completed, $hasHistory): object {
            $item->reason = $hasHistory
                ? ($completed
                    ? 'Fresh pick from Made For You after '.$completed
                    : 'A latest Made For You episode picked for you')
                : 'Popular on Pelevo while we learn what you like';

            return $item;
        });
    }

    /**
     * @return array{title: string, subtitle: string}
     */
    private function inspirationBanner(string $userId): array
    {
        if ($this->becauseInspiration !== null) {
            return $this->becauseInspiration;
        }

        $showTitle = DB::table('playback_progress')
            ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('playback_progress.user_id', $userId)
            ->orderByDesc('playback_progress.updated_at')
            ->value('shows.title');

        if (is_string($showTitle) && $showTitle !== '') {
            return [
                'title' => 'Because You Listened to '.$showTitle,
                'subtitle' => 'Related episodes from other podcasts in the same lane',
            ];
        }

        return [
            'title' => 'Because You Listened',
            'subtitle' => 'Similar episodes from other podcasts you have not finished',
        ];
    }

    private function unexploredCategories(string $userId): Collection
    {
        $this->ensurePodcastIndexCategories();

        $categories = $this->categoryQuery()->get();
        if ($categories->isEmpty()) {
            return collect();
        }

        $exploredIds = $this->exploredCategoryIds($userId);
        $exploredSlugs = $categories
            ->filter(fn (object $category): bool => $exploredIds->contains($category->id))
            ->pluck('slug')
            ->merge($this->exploredSlugsFromSearch($userId, $categories))
            ->map(fn (mixed $slug): string => Str::slug((string) $slug))
            ->filter()
            ->unique()
            ->values();

        $blocked = $this->blockedDiscoverySlugs($exploredSlugs);
        $heroes = $this->discoveryCategorySlugs();
        $bySlug = $categories->keyBy(fn (object $category): string => (string) $category->slug);

        $picks = collect();
        foreach ($heroes as $slug) {
            if (isset($blocked[$slug])) {
                continue;
            }
            $row = $bySlug->get($slug);
            if ($row !== null) {
                $picks->push($row);
            }
        }

        if ($picks->count() < 8) {
            foreach ($categories as $row) {
                if ($picks->count() >= 16) {
                    break;
                }
                $slug = (string) $row->slug;
                if (isset($blocked[$slug]) || $picks->contains(fn (object $picked): bool => $picked->id === $row->id)) {
                    continue;
                }
                $picks->push($row);
            }
        }

        if ($picks->isEmpty()) {
            $picks = collect($heroes)
                ->map(fn (string $slug): ?object => $bySlug->get($slug))
                ->filter()
                ->values();
        }

        if ($picks->isEmpty()) {
            $picks = $categories->values();
        }

        $count = $picks->count();
        $offset = $count > 1 ? abs(crc32($userId)) % $count : 0;

        return $picks->slice($offset)->concat($picks->slice(0, $offset))->take(16)->values();
    }

    private function categories(int $limit): Collection
    {
        $this->ensurePodcastIndexCategories();

        return $this->categoryQuery()->limit($limit)->get();
    }

    private function ensurePodcastIndexCategories(): void
    {
        if (DB::table('categories')->where('active', true)->exists()) {
            return;
        }

        app(SyncPodcastIndexCategories::class)->handle(invalidateCache: false);
    }

    /**
     * @return Collection<int, int|string>
     */
    private function exploredCategoryIds(string $userId): Collection
    {
        $fromPlayback = DB::table('category_show')
            ->join('episodes', 'episodes.show_id', '=', 'category_show.show_id')
            ->join('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->where('playback_progress.user_id', $userId)
            ->distinct()
            ->pluck('category_show.category_id');

        $fromFollows = DB::table('category_show')
            ->join('follows', 'follows.show_id', '=', 'category_show.show_id')
            ->where('follows.user_id', $userId)
            ->distinct()
            ->pluck('category_show.category_id');

        $fromSaves = DB::table('category_show')
            ->join('episodes', 'episodes.show_id', '=', 'category_show.show_id')
            ->join('episode_saves', 'episode_saves.episode_id', '=', 'episodes.id')
            ->where('episode_saves.user_id', $userId)
            ->distinct()
            ->pluck('category_show.category_id');

        return $fromPlayback->merge($fromFollows)->merge($fromSaves)->unique()->values();
    }

    /**
     * @param  Collection<int, object>  $categories
     * @return Collection<int, string>
     */
    private function exploredSlugsFromSearch(string $userId, Collection $categories): Collection
    {
        $queries = DB::table('search_history')
            ->where('user_id', $userId)
            ->pluck('query')
            ->map(fn (mixed $query): string => Str::lower(trim((string) $query)))
            ->filter(fn (string $query): bool => $query !== '');

        if ($queries->isEmpty()) {
            return collect();
        }

        return $categories
            ->filter(function (object $category) use ($queries): bool {
                $slug = Str::lower((string) $category->slug);
                $title = Str::lower((string) $category->title);
                foreach ($queries as $query) {
                    if ($query === $slug || $query === $title) {
                        return true;
                    }
                    if (strlen($query) >= 4 && (str_contains($slug, $query) || str_contains($title, $query) || str_contains($query, $slug) || str_contains($query, $title))) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('slug');
    }

    /**
     * @param  Collection<int, string>  $exploredSlugs
     * @return array<string, true>
     */
    private function blockedDiscoverySlugs(Collection $exploredSlugs): array
    {
        $families = $this->podcastIndexFamilies();
        $blocked = [];
        foreach ($exploredSlugs as $slug) {
            foreach ($families as $members) {
                if (! in_array($slug, $members, true)) {
                    continue;
                }
                foreach ($members as $member) {
                    $blocked[$member] = true;
                }
            }
            $blocked[$slug] = true;
        }

        return $blocked;
    }

    /**
     * Top-level Podcast Index categories that make useful discovery tiles.
     *
     * @return list<string>
     */
    private function discoveryCategorySlugs(): array
    {
        return [
            'science', 'comedy', 'sports', 'news', 'health', 'history', 'true-crime', 'kids',
            'technology', 'business', 'education', 'music', 'fiction', 'religion', 'society',
            'arts', 'leisure', 'tv', 'government', 'culture',
        ];
    }

    /**
     * Podcast Index parent/child families so listening to Soccer also counts as Sports.
     *
     * @return array<string, list<string>>
     */
    private function podcastIndexFamilies(): array
    {
        return [
            'arts' => ['arts', 'books', 'design', 'fashion', 'beauty', 'food', 'performing', 'visual'],
            'business' => ['business', 'careers', 'entrepreneurship', 'investing', 'management', 'marketing', 'non-profit', 'cryptocurrency'],
            'comedy' => ['comedy', 'interviews', 'improv', 'stand-up'],
            'education' => ['education', 'courses', 'how-to', 'language', 'learning', 'self-improvement'],
            'fiction' => ['fiction', 'drama'],
            'government' => ['government', 'politics'],
            'health' => ['health', 'fitness', 'alternative', 'medicine', 'mental', 'nutrition', 'sexuality'],
            'history' => ['history'],
            'kids' => ['kids', 'family', 'parenting', 'pets', 'animals', 'stories'],
            'leisure' => ['leisure', 'animation', 'manga', 'automotive', 'aviation', 'crafts', 'games', 'hobbies', 'home', 'garden', 'video-games', 'tabletop', 'role-playing'],
            'music' => ['music', 'commentary'],
            'news' => ['news', 'daily', 'entertainment'],
            'religion' => ['religion', 'spirituality', 'buddhism', 'christianity', 'hinduism', 'islam', 'judaism'],
            'science' => ['science', 'astronomy', 'chemistry', 'earth', 'life', 'mathematics', 'natural', 'nature', 'physics', 'social', 'climate', 'weather'],
            'society' => ['society', 'culture', 'documentary', 'personal', 'journals', 'philosophy', 'places', 'travel', 'relationships'],
            'sports' => ['sports', 'baseball', 'basketball', 'cricket', 'fantasy', 'football', 'golf', 'hockey', 'rugby', 'running', 'soccer', 'swimming', 'tennis', 'volleyball', 'wilderness', 'wrestling'],
            'technology' => ['technology'],
            'true-crime' => ['true-crime'],
            'tv' => ['tv', 'film', 'after-shows', 'reviews'],
        ];
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
            ->whereNotNull('follows.user_id')
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

        return $items;
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

        $cacheKey = 'home:discover-shows:'.hash('sha256', strtolower($query).'|'.($country ?? '').'|'.$limit);

        try {
            $ids = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($query, $limit, $country): array {
                $client = app(PodcastIndexClient::class);
                $persist = app(PersistDiscoveredShow::class);
                $feeds = $client->searchByTerm($query, $limit, fast: true)['feeds'] ?? [];
                $ids = [];
                foreach ($feeds as $feed) {
                    if (! is_array($feed) || count($ids) >= $limit) {
                        continue;
                    }
                    if ($show = $persist->handle($feed)) {
                        if ($country !== null && $show->country_code === null) {
                            $show->forceFill(['country_code' => $country])->save();
                        }
                        $ids[] = $show->id;
                    }
                }

                Log::info('home.discover.podcast_index', ['query' => $query, 'persisted' => count($ids)]);

                return $ids;
            });
        } catch (PodcastIndexException $exception) {
            Log::warning('home.discover.podcast_index_failed', [
                'query' => $query,
                'message' => $exception->getMessage(),
            ]);

            return collect();
        }

        if ($ids === []) {
            return collect();
        }

        return $this->shows()->whereIn('shows.id', $ids)->limit($limit)->get();
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
            ['key' => 'because_you_listened', 'title' => 'Because You Listened', 'subtitle' => 'Similar episodes from other podcasts you enjoy', 'kind' => 'because'],
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
