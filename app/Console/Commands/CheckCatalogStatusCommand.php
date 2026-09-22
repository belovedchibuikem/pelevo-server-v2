<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

final class CheckCatalogStatusCommand extends Command
{
    protected $signature = 'pelevo:catalog-status';

    protected $description = 'Diagnose Horizon, the scheduler, RSS queue, and last feed sync so episode updates can be traced.';

    public function handle(): int
    {
        $horizon = 'unknown';
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
            $horizon = $masters === [] ? 'inactive (no master supervisor)' : 'running ('.count($masters).' master)';
        } catch (\Throwable $error) {
            $horizon = 'error: '.$error->getMessage();
        }

        $heartbeat = Cache::get('scheduler:last_heartbeat_at');
        $heartbeatRow = Schema::hasTable('scheduler_heartbeats')
            ? DB::table('scheduler_heartbeats')->orderByDesc('ran_at')->first(['ran_at', 'host'])
            : null;
        $rssDepth = Queue::connection()->size('rss');
        $defaultDepth = Queue::connection()->size('default');
        $notificationsDepth = Queue::connection()->size('notifications');
        $maxDepth = (int) config('catalog.rss_queue_max_depth');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $due = Schema::hasTable('show_feed_states')
            ? DB::table('show_feed_states')->where(function ($query): void {
                $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now());
            })->count()
            : 0;
        $running = Schema::hasTable('show_feed_states')
            ? DB::table('show_feed_states')->where('state', 'running')->count()
            : 0;
        $failedFeeds = Schema::hasTable('show_feed_states')
            ? DB::table('show_feed_states')->where('state', 'failed')->count()
            : 0;
        $latestSync = Schema::hasTable('feed_sync_runs')
            ? DB::table('feed_sync_runs')->orderByDesc('started_at')->first(['show_id', 'state', 'new_episode_count', 'http_status', 'started_at', 'finished_at', 'error'])
            : null;
        $latestEpisode = Schema::hasTable('episodes')
            ? DB::table('episodes')->orderByDesc('created_at')->first(['id', 'title', 'show_id', 'created_at'])
            : null;

        $this->table(['Check', 'Value'], [
            ['queue connection', (string) config('queue.default')],
            ['Horizon', $horizon],
            ['scheduler cache heartbeat', is_string($heartbeat) ? $heartbeat : 'MISSING (cron is not running schedule:run)'],
            ['scheduler DB heartbeat', $heartbeatRow ? $heartbeatRow->ran_at.' on '.$heartbeatRow->host : 'none'],
            ['rss queue depth', (string) $rssDepth.($rssDepth >= $maxDepth ? ' BACKPRESSURE — catalog:poll-feeds will not enqueue more' : '')],
            ['default queue depth', (string) $defaultDepth],
            ['notifications queue depth', (string) $notificationsDepth],
            ['failed_jobs', (string) $failed],
            ['feeds due now', (string) $due],
            ['feeds stuck running', (string) $running],
            ['feeds in failed state', (string) $failedFeeds],
        ]);

        if ($latestSync) {
            $this->line('Latest feed_sync_runs: '.$latestSync->state
                .' episodes+'.$latestSync->new_episode_count
                .' HTTP '.$latestSync->http_status
                .' started '.$latestSync->started_at
                .' finished '.($latestSync->finished_at ?: '(still running)'));
            if (filled($latestSync->error)) {
                $this->warn('Last sync error: '.$latestSync->error);
            }
        } else {
            $this->warn('No feed_sync_runs rows. catalog:poll-feeds has never completed a hydrate.');
        }

        if ($latestEpisode) {
            $this->line('Newest episode row: '.$latestEpisode->title.' at '.$latestEpisode->created_at);
        }

        $failedHydrates = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('payload', 'like', '%HydrateRssFeed%')->orderByDesc('failed_at')->limit(5)->get(['id', 'queue', 'failed_at', 'exception'])
            : collect();
        if ($failedHydrates->isNotEmpty()) {
            $this->error('Recent HydrateRssFeed failures:');
            foreach ($failedHydrates as $row) {
                $this->line($row->failed_at.' queue='.$row->queue.' '.Str::limit(str_replace("\n", ' ', (string) $row->exception), 220));
            }
        }

        $healthy = true;
        if (! is_string($heartbeat) || Carbon::parse($heartbeat)->lt(now()->subMinutes(3))) {
            $this->error('Scheduler is stale or missing. Horizon can be running and still never poll RSS. On Forge, cron must run: php artisan schedule:run every minute.');
            $healthy = false;
        }
        if (! str_starts_with($horizon, 'running')) {
            $this->error('Horizon is not actually supervising workers.');
            $healthy = false;
        }
        if ($rssDepth >= $maxDepth) {
            $this->error("RSS queue is at max depth ({$maxDepth}). New episode polls are skipped until workers drain the rss queue.");
            $healthy = false;
        }
        if ($healthy) {
            $this->info('Core path looks up. If a specific show is stale, run: php artisan catalog:poll-feeds && php artisan pelevo:hydrate-show "Show Title"');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
