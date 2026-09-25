<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
        $pool = $this->pool($query);
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

    private function pool(Builder $query)
    {
        try {
            return $this->scored($query)->get();
        } catch (Throwable $exception) {
            Log::warning('reel.feed.ranker.score_failed', [
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return $this->recent($query)->get()->each(function (object $row): void {
                $row->popularity_score = 0;
            });
        }
    }

    private function scored(Builder $query): Builder
    {
        $includeShares = Schema::hasTable('share_cards');
        $ranked = $this->recent($query)
            ->leftJoinSub(
                DB::table('reel_view_credits')->select('reel_id', DB::raw('count(*) as c'))->groupBy('reel_id'),
                'reel_rank_views',
                'reel_rank_views.reel_id',
                '=',
                'reels.id',
            )
            ->leftJoinSub(
                DB::table('reel_engagements')->where('liked', true)->select('reel_id', DB::raw('count(*) as c'))->groupBy('reel_id'),
                'reel_rank_likes',
                'reel_rank_likes.reel_id',
                '=',
                'reels.id',
            )
            ->leftJoinSub(
                DB::table('reel_engagements')->where('saved', true)->select('reel_id', DB::raw('count(*) as c'))->groupBy('reel_id'),
                'reel_rank_saves',
                'reel_rank_saves.reel_id',
                '=',
                'reels.id',
            )
            ->leftJoinSub(
                DB::table('comments')->where('commentable_type', 'reel')->whereNull('hidden_at')->select('commentable_id as reel_id', DB::raw('count(*) as c'))->groupBy('commentable_id'),
                'reel_rank_comments',
                'reel_rank_comments.reel_id',
                '=',
                'reels.id',
            );
        if ($includeShares) {
            $ranked->leftJoinSub(
                DB::table('share_cards')->where('subject_type', 'reel')->select('subject_id as reel_id', DB::raw('count(*) as c'))->groupBy('subject_id'),
                'reel_rank_shares',
                'reel_rank_shares.reel_id',
                '=',
                'reels.id',
            );
        }
        $shareExpr = $includeShares ? 'reel_rank_shares.c' : '0';

        return $ranked->selectRaw(
            '(coalesce(reel_rank_views.c, 0)'
            .' + coalesce(reel_rank_likes.c, 0) * 8'
            .' + coalesce(reel_rank_saves.c, 0) * 7'
            .' + coalesce(reel_rank_comments.c, 0) * 6'
            .' + coalesce('.$shareExpr.', 0) * 9) as popularity_score',
        );
    }

    private function recent(Builder $query): Builder
    {
        return (clone $query)
            ->select('reels.*')
            ->orderByDesc('reels.published_at')
            ->orderByDesc('reels.id')
            ->limit(self::POOL);
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
        } catch (Throwable) {
            return false;
        }
    }
}
