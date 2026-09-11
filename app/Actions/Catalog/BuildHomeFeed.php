<?php

namespace App\Actions\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BuildHomeFeed
{
    public function handle(string $userId): array
    {
        return $this->activeModules()
            ->map(fn (array $module): array => $this->present($module, $this->datasets($userId)[$module['key']] ?? collect()))
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

        return $this->present($module, $this->datasets($userId, $filters)[$key] ?? collect());
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function present(array $module, Collection $items): array
    {
        return [
            'id' => 'home.'.$module['key'],
            'key' => $module['key'],
            'type' => $module['kind'],
            'title' => $module['title'],
            'subtitle' => $module['subtitle'],
            'see_all_url' => '/api/v1/home/rails/'.$module['key'],
            'items' => $items->values()->all(),
        ];
    }

    /**
     * @param  array{min_duration?: int, max_duration?: int, country?: string}  $filters
     * @return array<string, Collection<int, object>>
     */
    private function datasets(string $userId, array $filters = []): array
    {
        $trending = $this->trending();
        $madeForYou = $this->affinityEpisodes($userId, $trending);

        return [
            'moods' => $this->categories(6),
            'pick_for_today' => $madeForYou->take(1),
            'continue_listening' => $this->continueListening($userId),
            'shows_you_follow' => $this->followedShows($userId),
            'made_for_you' => $madeForYou,
            'quick_listen' => $this->quickListen($filters['min_duration'] ?? null, $filters['max_duration'] ?? null),
            'because_you_listened' => $this->affinityEpisodes($userId, $trending, true),
            'trending' => $trending,
            'new_from_following' => $this->following($userId),
            'african_voices' => $this->africanVoiceShows($filters['country'] ?? null),
            'try_something_new' => $this->unexploredCategories($userId),
            'explore_by_topic' => $this->categories(30),
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

    private function followedShows(string $userId): Collection
    {
        return $this->shows()
            ->join('follows', 'follows.show_id', '=', 'shows.id')
            ->where('follows.user_id', $userId)
            ->orderByDesc('follows.created_at')
            ->orderByDesc('shows.id')
            ->limit(30)
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

        return $this->shows()
            ->whereIn('shows.country_code', $selected !== null && in_array($selected, $countries, true) ? [$selected] : $countries)
            ->orderByDesc('shows.updated_at')
            ->orderByDesc('shows.id')
            ->limit(30)
            ->get();
    }

    private function trending(): Collection
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

    private function unexploredCategories(string $userId): Collection
    {
        $explored = DB::table('category_show')
            ->join('episodes', 'episodes.show_id', '=', 'category_show.show_id')
            ->join('playback_progress', 'playback_progress.episode_id', '=', 'episodes.id')
            ->where('playback_progress.user_id', $userId)
            ->distinct()
            ->pluck('category_show.category_id');

        return $this->categoryQuery()
            ->when($explored->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('categories.id', $explored))
            ->limit(12)
            ->get();
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
            ['key' => 'moods', 'title' => 'What are you in the mood for?', 'subtitle' => 'Browse topics that match this moment', 'kind' => 'categories'],
            ['key' => 'pick_for_today', 'title' => 'Your pick for today', 'subtitle' => 'One episode selected from Pelevo recommendations', 'kind' => 'hero_episode'],
            ['key' => 'continue_listening', 'title' => 'Continue listening', 'subtitle' => 'Pick up where you left off', 'kind' => 'episode_progress'],
            ['key' => 'shows_you_follow', 'title' => 'Shows you follow', 'subtitle' => 'Open a podcast to browse its episodes', 'kind' => 'shows'],
            ['key' => 'made_for_you', 'title' => 'Made for you', 'subtitle' => 'Personalized episode picks', 'kind' => 'episodes'],
            ['key' => 'quick_listen', 'title' => 'Quick listen', 'subtitle' => 'Short episodes that fit your time', 'kind' => 'episodes'],
            ['key' => 'because_you_listened', 'title' => 'Because you listened', 'subtitle' => 'More from topics you already enjoy', 'kind' => 'episodes'],
            ['key' => 'trending', 'title' => 'Trending on Pelevo', 'subtitle' => 'What listeners are playing now', 'kind' => 'ranked_episodes'],
            ['key' => 'new_from_following', 'title' => 'New from shows you follow', 'subtitle' => 'Fresh episodes from your subscriptions', 'kind' => 'episodes'],
            ['key' => 'african_voices', 'title' => 'African voices', 'subtitle' => 'Podcasts from across the continent', 'kind' => 'shows'],
            ['key' => 'try_something_new', 'title' => 'Try something new', 'subtitle' => 'Topics outside your listening history', 'kind' => 'categories'],
            ['key' => 'explore_by_topic', 'title' => 'Explore by topic', 'subtitle' => 'Browse the Pelevo catalog', 'kind' => 'categories'],
        ];
    }
}
