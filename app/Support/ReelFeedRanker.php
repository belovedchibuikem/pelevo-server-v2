<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

final class ReelFeedRanker
{
    public const POOL = 240;

    public const EXPLOIT_PER_CYCLE = 4;

    public const EXPLORE_MAX_SCORE = 7;

    public const EXPLORE_MAX_AGE_DAYS = 7;

    /**
     * @return array{0: list<object>, 1: bool}
     */
    public function page(Builder $query, int $limit, int $offset): array
    {
        $pool = (clone $query)
            ->select('reels.*')
            ->selectRaw($this->scoreSql().' as popularity_score')
            ->orderByDesc('reels.published_at')
            ->orderByDesc('reels.id')
            ->limit(self::POOL)
            ->get();
        $popular = $pool
            ->sortByDesc(fn (object $row): array => [(int) ($row->popularity_score ?? 0), (string) $row->id])
            ->values()
            ->all();
        $explore = $pool
            ->filter(fn (object $row): bool => $this->isExplore($row))
            ->sortByDesc(fn (object $row): array => [(string) ($row->published_at ?? ''), (string) $row->id])
            ->values()
            ->all();
        $ordered = self::interleave($popular, $explore);
        $slice = array_slice($ordered, $offset, $limit);

        return [$slice, ($offset + $limit) < count($ordered)];
    }

    /**
     * Mix 80% high-engagement reels with 20% new or low-view reels.
     *
     * @param  list<object>  $popular
     * @param  list<object>  $explore
     * @return list<object>
     */
    public static function interleave(array $popular, array $explore): array
    {
        $seen = [];
        $out = [];
        $take = static function (object $row) use (&$seen): ?object {
            $id = (string) $row->id;
            if ($id === '' || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;

            return $row;
        };
        $pi = 0;
        $ei = 0;
        $popularCount = count($popular);
        $exploreCount = count($explore);
        while ($pi < $popularCount || $ei < $exploreCount) {
            $taken = 0;
            while ($taken < self::EXPLOIT_PER_CYCLE && $pi < $popularCount) {
                $row = $take($popular[$pi++]);
                if ($row !== null) {
                    $out[] = $row;
                    $taken++;
                }
            }
            while ($ei < $exploreCount) {
                $row = $take($explore[$ei++]);
                if ($row !== null) {
                    $out[] = $row;
                    break;
                }
            }
            if ($taken === 0 && $ei >= $exploreCount) {
                break;
            }
        }

        return $out;
    }

    private function isExplore(object $row): bool
    {
        $score = (int) ($row->popularity_score ?? 0);
        if ($score <= self::EXPLORE_MAX_SCORE) {
            return true;
        }
        $published = $row->published_at ?? null;
        if (! is_string($published) && ! $published instanceof \DateTimeInterface) {
            return false;
        }
        try {
            return Carbon::parse($published)->gte(now()->subDays(self::EXPLORE_MAX_AGE_DAYS));
        } catch (\Throwable) {
            return false;
        }
    }

    private function scoreSql(): string
    {
        return '('.
            '(select count(*) from reel_view_credits where reel_view_credits.reel_id = reels.id)'.
            ' + (select count(*) from reel_engagements where reel_engagements.reel_id = reels.id and reel_engagements.liked = 1) * 8'.
            ' + (select count(*) from reel_engagements where reel_engagements.reel_id = reels.id and reel_engagements.saved = 1) * 7'.
            ' + (select count(*) from comments where comments.commentable_type = \'reel\' and comments.commentable_id = reels.id and comments.hidden_at is null) * 6'.
            ' + (select count(*) from share_cards where share_cards.subject_type = \'reel\' and share_cards.subject_id = reels.id) * 9'.
            ')';
    }
}
