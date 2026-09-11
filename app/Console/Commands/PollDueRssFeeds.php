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

        $showIds->each(fn (string $showId) => HydrateRssFeed::dispatch($showId));
        $this->info("Queued {$showIds->count()} due RSS feeds.");

        return self::SUCCESS;
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
