<?php

namespace App\Actions\Ai;

use App\Integrations\Ai\AiProviderException;
use App\Integrations\Ai\LanguageModel;
use App\Support\ArtworkUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversational show discovery and the weekly lineup.
 *
 * Recommendations come from the catalog plus the listener's own history,
 * so the feature works without an external model.
 */
final class RecommendListening
{
    public function __construct(private readonly LanguageModel $model) {}
    /**
     * @return array{reply: string, items: list<array<string, mixed>>}
     */
    public function ask(string $userId, string $message): array
    {
        $message = trim($message);
        $mode = $this->model->configured() ? 'model' : 'catalog';
        $key = 'ai:ask:v2:'.$userId.':'.$mode.':'.hash('sha256', Str::lower($message));

        return Cache::remember($key, now()->addMinutes(2), function () use ($userId, $message): array {
            $terms = $this->terms($message);
            $context = $this->listeningContext($userId);
            $items = $this->picks($userId, $terms, $context, 8);
            $clip = Str::limit($message, 140, '');
            $reply = $terms === []
                ? 'Describe what you want to hear — a topic, a mood, or the kind of conversation you are in the mood for. These picks start from your listening history.'
                : 'You asked for '.$clip.'. These shows use that request, your interests, and your listening history, including ones you might not search for by name.';
            if ($items === []) {
                $reply .= ' Nothing in the catalog matches that yet. Try a broader topic.';
            } else {
                [$reply, $items] = $this->narrate($message, $reply, $items, false);
            }

            return ['reply' => $reply, 'items' => $items];
        });
    }

    /**
     * @return array{title: string, week_label: string, intro: string, items: list<array<string, mixed>>}
     */
    public function lineup(string $userId): array
    {
        $week = now()->format('o-\WW');
        $mode = $this->model->configured() ? 'model' : 'catalog';
        $key = 'ai:lineup:v2:'.$userId.':'.$week.':'.$mode;

        return Cache::remember($key, now()->addHours(12), function () use ($userId): array {
            $context = $this->listeningContext($userId);
            $items = $this->picks($userId, [], $context, 8);
            $intro = 'A personalised weekly audio guide. Each show is here because of your interests and listening history, with a note on why it is worth your time.';
            if ($items !== []) {
                [$intro, $items] = $this->narrate('Build this week\'s podcast lineup from my listening.', $intro, $items, true);
            }

            return [
                'title' => 'Your Podcast Lineup',
                'week_label' => 'Week of '.now()->startOfWeek()->format('j F'),
                'intro' => $intro,
                'items' => $items,
            ];
        });
    }

    /**
     * Rewrite the reply with the model, but only for shows already chosen from the catalog.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function narrate(string $message, string $fallback, array $items, bool $lineup): array
    {
        if (! $this->model->configured()) {
            return [$fallback, $items];
        }
        $catalog = [];
        foreach ($items as $item) {
            $catalog[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'reason' => $item['reason'] ?? '',
            ];
        }
        $instructions = 'You help a listener choose a podcast. Use only the shows in the JSON catalog. Do not invent shows, hosts, or episodes. Reply with JSON only: {"reply":"","reasons":{"SHOW_ID":"one sentence why it is worth listening to"}}';
        $content = ($lineup ? "Write a two-sentence weekly lineup introduction.\n" : "The listener said: {$message}\n")
            .'Catalog: '.json_encode($catalog, JSON_THROW_ON_ERROR);
        try {
            $completion = $this->model->complete($instructions, $content, 12);
            $decoded = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $completion->text) ?? $completion->text), true);
        } catch (AiProviderException) {
            return [$fallback, $items];
        } catch (\JsonException) {
            return [$fallback, $items];
        }
        if (! is_array($decoded)) {
            return [$fallback, $items];
        }
        $reply = is_string($decoded['reply'] ?? null) ? trim($decoded['reply']) : '';
        $reasons = is_array($decoded['reasons'] ?? null) ? $decoded['reasons'] : [];
        foreach ($items as $index => $item) {
            $reason = $reasons[(string) $item['id']] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                $items[$index]['reason'] = trim($reason);
            }
        }

        return [$reply !== '' ? $reply : $fallback, $items];
    }

    /**
     * @param  list<string>  $terms
     * @param  array{recent_ids: array<string, true>, recent_title: string|null, followed_ids: array<string, true>}  $context
     * @return list<array<string, mixed>>
     */
    private function picks(string $userId, array $terms, array $context, int $limit): array
    {
        $candidates = $terms === []
            ? $this->historyCandidates($context)
            : $this->searchCandidates($terms);

        if ($candidates->isEmpty() && $terms !== []) {
            $candidates = $this->historyCandidates($context);
        }

        $focus = $terms[0] ?? null;

        return $candidates
            ->map(function (object $row) use ($context, $focus, $terms): object {
                $showId = (string) $row->id;
                $haystack = Str::lower(trim(($row->title ?? '').' '.($row->author ?? '').' '.($row->description ?? '').' '.($row->tags ?? '')));
                $hits = 0;
                foreach ($terms as $term) {
                    if ($term !== '' && str_contains($haystack, $term)) {
                        $hits++;
                    }
                }
                $row->score = ($hits * 20)
                    + (isset($context['followed_ids'][$showId]) ? 24 : 0)
                    + (isset($context['recent_ids'][$showId]) ? 16 : 0)
                    + ((int) ($row->play_count ?? 0) > 0 ? 6 : 0);
                $row->reason = $this->reason($row, $context, $focus, $hits);

                return $row;
            })
            ->sortByDesc(fn (object $row): int => (int) $row->score)
            ->unique('id')
            ->take($limit)
            ->values()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'type' => 'show',
                'title' => (string) $row->title,
                'show_id' => (string) $row->id,
                'show_title' => (string) $row->title,
                'artwork_url' => ArtworkUrl::sanitize($row->artwork_url ?? null),
                'reason' => (string) $row->reason,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $terms
     */
    private function searchCandidates(array $terms): \Illuminate\Support\Collection
    {
        $boolean = $this->booleanQuery($terms);
        $driver = DB::connection()->getDriverName();

        return DB::table('shows')
            ->where('shows.status', 'active')
            ->where(function ($query) use ($terms, $boolean, $driver): void {
                if ($boolean !== null && $driver === 'mysql') {
                    $focused = $this->booleanQuery(array_slice($terms, 0, 3)) ?? $boolean;
                    $query->whereRaw('MATCH(shows.title, shows.description, shows.author) AGAINST (? IN BOOLEAN MODE)', [$focused]);

                    return;
                }
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $query->orWhere('shows.title', 'like', $like)
                        ->orWhere('shows.author', 'like', $like);
                }
            })
            ->orderByDesc('shows.updated_at')
            ->limit(40)
            ->get([
                'shows.id',
                'shows.title',
                'shows.author',
                'shows.artwork_url',
                DB::raw('LEFT(shows.description, 180) as description'),
                DB::raw('NULL as tags'),
                DB::raw('0 as play_count'),
            ]);
    }

    /**
     * @param  array{recent_ids: array<string, true>, recent_title: string|null, followed_ids: array<string, true>}  $context
     */
    private function historyCandidates(array $context): \Illuminate\Support\Collection
    {
        $seedIds = array_keys($context['recent_ids'] + $context['followed_ids']);
        $categoryIds = $seedIds === []
            ? collect()
            : DB::table('category_show')->whereIn('show_id', $seedIds)->pluck('category_id');

        $query = DB::table('shows')->where('shows.status', 'active');
        if ($categoryIds->isNotEmpty()) {
            $query->whereExists(function ($exists) use ($categoryIds): void {
                $exists->selectRaw('1')
                    ->from('category_show')
                    ->whereColumn('category_show.show_id', 'shows.id')
                    ->whereIn('category_show.category_id', $categoryIds);
            });
        } elseif ($seedIds !== []) {
            $query->whereIn('shows.id', $seedIds);
        }

        return $query
            ->orderByDesc('shows.updated_at')
            ->limit(40)
            ->get([
                'shows.id',
                'shows.title',
                'shows.author',
                'shows.artwork_url',
                DB::raw('LEFT(shows.description, 180) as description'),
                DB::raw('NULL as tags'),
                DB::raw('0 as play_count'),
            ]);
    }

    /**
     * @return array{recent_ids: array<string, true>, recent_title: string|null, followed_ids: array<string, true>}
     */
    private function listeningContext(string $userId): array
    {
        $recent = DB::table('playback_progress')
            ->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('playback_progress.user_id', $userId)
            ->orderByDesc('playback_progress.updated_at')
            ->limit(20)
            ->get(['shows.id as show_id', 'shows.title']);

        $followed = DB::table('follows')->where('user_id', $userId)->limit(30)->pluck('show_id');
        $recentIds = [];
        foreach ($recent as $row) {
            $recentIds[(string) $row->show_id] = true;
        }
        $followedIds = [];
        foreach ($followed as $id) {
            $followedIds[(string) $id] = true;
        }

        return [
            'recent_ids' => $recentIds,
            'recent_title' => isset($recent[0]) ? (string) $recent[0]->title : null,
            'followed_ids' => $followedIds,
        ];
    }

    /**
     * @param  array{recent_ids: array<string, true>, recent_title: string|null, followed_ids: array<string, true>}  $context
     */
    private function reason(object $row, array $context, ?string $focus, int $hits): string
    {
        $showId = (string) $row->id;
        if ($context['recent_title'] !== null && isset($context['recent_ids'][$showId]) && $showId !== array_key_first($context['recent_ids'])) {
            return 'Worth listening to because it sits next to '.$context['recent_title'].' in your history.';
        }
        if (isset($context['followed_ids'][$showId])) {
            return 'Worth listening to because you already follow this show.';
        }
        if ($focus !== null && $hits > 0) {
            return 'Worth listening to because it matches “'.$focus.'” without you having to search the show by name.';
        }
        if ($context['recent_title'] !== null) {
            return 'Worth listening to because it is close to '.$context['recent_title'].' from your recent listening.';
        }

        return 'Worth listening to because listeners with similar taste keep coming back to it.';
    }

    /**
     * @return list<string>
     */
    private function terms(string $message): array
    {
        $stop = [
            'a', 'an', 'the', 'and', 'or', 'to', 'for', 'of', 'in', 'on', 'with', 'me', 'my', 'i',
            'want', 'something', 'like', 'about', 'show', 'shows', 'podcast', 'podcasts', 'listen',
            'listening', 'hear', 'please', 'what', 'that', 'this', 'from', 'around', 'similar',
            'recommend', 'recommendation', 'episode', 'episodes', 'next', 'can', 'you', 'your',
        ];
        $parts = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($message), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }
        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 3 || in_array($part, $stop, true)) {
                continue;
            }
            $terms[$part] = $part;
        }

        return array_values($terms);
    }

    /**
     * @param  list<string>  $terms
     */
    private function booleanQuery(array $terms): ?string
    {
        if ($terms === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $term): string => '+'.$term.'*', $terms));
    }
}
