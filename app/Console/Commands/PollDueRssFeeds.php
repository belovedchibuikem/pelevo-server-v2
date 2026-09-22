<?php

namespace App\Console\Commands;

use App\Jobs\HydrateRssFeed;
use App\Models\ShowFeedState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class PollDueRssFeeds extends Command
{
    protected $signature = 'catalog:poll-feeds';

    protected $description = 'Backfill feed state and queue due RSS feeds for hydration';

    public function handle(): int
    {
        $this->backfillFeedStates();
        $released = $this->releaseStuckRunningFeeds();
        $nudged = $this->nudgeStaleFeeds();

        $depth = Queue::connection()->size('rss');
        if ($depth >= config('catalog.rss_queue_max_depth')) {
            $this->warn("RSS queue backpressure active at {$depth} jobs.");

            return self::SUCCESS;
        }

        $available = max(0, config('catalog.rss_queue_max_depth') - $depth);
        $limit = min(config('catalog.rss_poll_batch_size'), $available);
        $showIds = ShowFeedState::query()
            ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->orderByRaw('(select count(*) from follows where follows.show_id = show_feed_states.show_id) desc')
            ->orderBy('next_poll_at')
            ->orderBy('show_id')
            ->limit($limit)
            ->pluck('show_id');

        $queued = 0;
        foreach ($showIds as $showId) {
            HydrateRssFeed::dispatch($showId);
            $queued++;
        }
        $this->info("Dispatched {$queued} due RSS feeds (rss depth {$depth}, due selected {$showIds->count()}, followed/stale nudged {$nudged}, stuck-running released {$released}). Laravel skips shows that already have a unique hydrate in flight.");

        return self::SUCCESS;
    }

    private function releaseStuckRunningFeeds(): int
    {
        return (int) DB::table('show_feed_states')
            ->where('state', 'running')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update([
                'state' => 'pending',
                'next_poll_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Followed shows must not wait a week. Also retry dead hosts, 404s, and
     * oversized feeds that older workers marked stale.
     */
    private function nudgeStaleFeeds(): int
    {
        $ids = $this->followedShowsWaitingTooLong()
            ->merge($this->recoverableErrorShowIds())
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return (int) DB::table('show_feed_states')
            ->whereIn('show_id', $ids)
            ->update([
                'next_poll_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function followedShowsWaitingTooLong()
    {
        return DB::table('show_feed_states')
            ->whereIn('state', ['failed', 'stale'])
            ->where('next_poll_at', '>', now()->addMinutes(20))
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('follows')->whereColumn('follows.show_id', 'show_feed_states.show_id');
            })
            ->orderBy('next_poll_at')
            ->limit(200)
            ->pluck('show_id');
    }

    private function recoverableErrorShowIds()
    {
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return DB::table('show_feed_states')
            ->whereIn('state', ['failed', 'stale'])
            ->where('next_poll_at', '>', now()->addHours(2))
            ->where(function ($query) use ($like): void {
                $query->where('last_error', $like, '%could not be resolved%')
                    ->orWhere('last_error', $like, '%could not resolve host%')
                    ->orWhere('last_error', $like, '%cURL error 6%')
                    ->orWhere('last_error', $like, '%cURL error 7%')
                    ->orWhere('last_error', $like, '%RSS feed returned HTTP 404%')
                    ->orWhere('last_error', $like, '%RSS feed returned status code 404%')
                    ->orWhere('last_error', $like, '%HTTP request returned status code 404%')
                    ->orWhere('last_error', $like, '%RSS feed returned HTTP 410%')
                    ->orWhere('last_error', $like, '%HTTP request returned status code 410%')
                    ->orWhere('last_error', $like, '%RSS feed returned HTTP 403%')
                    ->orWhere('last_error', $like, '%HTTP request returned status code 403%')
                    ->orWhere('last_error', $like, '%exceeds the configured limit%')
                    ->orWhere('last_error', $like, '%malformed%');
            })
            ->orderBy('last_failure_at')
            ->limit(200)
            ->pluck('show_id');
    }

    private function backfillFeedStates(): void
    {
        $showIds = DB::table('shows')
            ->leftJoin('show_feed_states', 'show_feed_states.show_id', '=', 'shows.id')
            ->where('shows.status', 'active')
            ->whereNull('show_feed_states.show_id')
            ->orderBy('shows.id')
            ->limit(config('catalog.feed_state_backfill_batch_size'))
            ->pluck('shows.id');

        foreach ($showIds as $showId) {
            DB::table('show_feed_states')->insertOrIgnore([
                'show_id' => $showId,
                'state' => 'pending',
                'consecutive_failures' => 0,
                'next_poll_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
