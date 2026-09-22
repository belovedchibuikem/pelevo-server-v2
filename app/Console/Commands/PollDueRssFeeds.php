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
        $nudged = $this->nudgeDeadHostFeeds();

        $depth = Queue::connection()->size('rss');
        if ($depth >= config('catalog.rss_queue_max_depth')) {
            $this->warn("RSS queue backpressure active at {$depth} jobs.");

            return self::SUCCESS;
        }

        $available = max(0, config('catalog.rss_queue_max_depth') - $depth);
        $limit = min(config('catalog.rss_poll_batch_size'), $available);
        $showIds = ShowFeedState::query()
            ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->orderBy('next_poll_at')
            ->orderBy('show_id')
            ->limit($limit)
            ->pluck('show_id');

        $queued = 0;
        foreach ($showIds as $showId) {
            HydrateRssFeed::dispatch($showId);
            $queued++;
        }
        $this->info("Dispatched {$queued} due RSS feeds (rss depth {$depth}, due selected {$showIds->count()}, dead-host nudged {$nudged}). Laravel skips shows that already have a unique hydrate in flight.");

        return self::SUCCESS;
    }

    /**
     * Feeds whose host vanished (DNS NXDOMAIN) were backing off for up to a
     * week, so Podcast Index never got a chance to supply the moved URL.
     */
    private function nudgeDeadHostFeeds(): int
    {
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $ids = DB::table('show_feed_states')
            ->whereIn('state', ['failed', 'stale'])
            ->where('next_poll_at', '>', now()->addHours(2))
            ->where(function ($query) use ($like): void {
                $query->where('last_error', $like, '%could not be resolved%')
                    ->orWhere('last_error', $like, '%could not resolve host%')
                    ->orWhere('last_error', $like, '%cURL error 6%')
                    ->orWhere('last_error', $like, '%cURL error 7%')
                    ->orWhere('last_error', $like, '%RSS feed returned HTTP 404%')
                    ->orWhere('last_error', $like, '%RSS feed returned HTTP 410%')
                    ->orWhere('last_error', $like, '%RSS feed returned HTTP 403%');
            })
            ->orderBy('last_failure_at')
            ->limit(100)
            ->pluck('show_id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('show_feed_states')
            ->whereIn('show_id', $ids)
            ->update([
                'next_poll_at' => now(),
                'updated_at' => now(),
            ]);
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
