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

        $this->printShowsMissingUpdates();

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
            $this->info('Core path looks up. catalog:poll-feeds already runs every 5 minutes; dead hosts now retry hourly via Podcast Index.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    private function printShowsMissingUpdates(): void
    {
        if (! Schema::hasTable('show_feed_states') || ! Schema::hasTable('shows')) {
            return;
        }

        $rows = DB::table('show_feed_states as sfs')
            ->join('shows', 'shows.id', '=', 'sfs.show_id')
            ->where(function ($query): void {
                $query->whereIn('sfs.state', ['failed', 'stale'])
                    ->orWhereNotNull('sfs.last_error');
            })
            ->select([
                'shows.id',
                'shows.title',
                'shows.rss_url',
                'sfs.state',
                'sfs.last_error',
                'sfs.last_success_at',
                'sfs.next_poll_at',
            ])
            ->selectSub(
                Schema::hasTable('follows')
                    ? DB::table('follows')->selectRaw('count(*)')->whereColumn('follows.show_id', 'shows.id')
                    : DB::query()->selectRaw('0'),
                'followers'
            )
            ->selectSub(
                Schema::hasTable('episodes')
                    ? DB::table('episodes')->select('title')->whereColumn('episodes.show_id', 'shows.id')->orderByDesc('published_at')->orderByDesc('created_at')->limit(1)
                    : DB::query()->selectRaw('null'),
                'newest_episode'
            )
            ->orderByDesc('followers')
            ->orderByDesc('sfs.last_failure_at')
            ->limit(40)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No failed/stale feeds. Shows that other apps already have are not sitting in a dead-host error state.');

            return;
        }

        $this->warn('Shows not updating in Pelevo (failed/stale RSS). Followed shows are listed first. This is not Spotify cache — these feeds never completed a successful parse.');
        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s  followers=%s  state=%s  newest=%s',
                $row->title,
                $row->followers,
                $row->state,
                $row->newest_episode ?: '(none)'
            ));
            $this->line('  rss: '.$row->rss_url);
            $this->line('  last success: '.($row->last_success_at ?: 'never').'  next poll: '.($row->next_poll_at ?: 'due'));
            if (filled($row->last_error)) {
                $this->line('  error: '.str_replace(["\n", "\r"], ' ', (string) $row->last_error));
            }
        }
    }
}
