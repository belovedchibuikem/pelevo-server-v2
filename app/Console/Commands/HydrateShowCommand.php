<?php

namespace App\Console\Commands;

use App\Actions\Catalog\PersistDiscoveredShow;
use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Jobs\HydrateRssFeed;
use App\Models\Show;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class HydrateShowCommand extends Command
{
    protected $signature = 'pelevo:hydrate-show
        {show? : Show ULID or title (exact or partial)}
        {--sync : Run hydration in this process instead of the rss queue}';

    protected $description = 'Queue (or run) RSS episode hydration for a discovered show.';

    public function handle(PodcastIndexClient $podcastIndex, PersistDiscoveredShow $persist): int
    {
        $needle = trim((string) $this->argument('show'));
        if ($needle === '') {
            $this->error('Pass a show id or title, e.g. pelevo:hydrate-show "Talk Tech Nigeria"');

            return self::FAILURE;
        }

        $show = $this->findShow($needle);
        if (! $show) {
            $this->error('Show not found.');

            return self::FAILURE;
        }

        $this->refreshFromPodcastIndex($show, $podcastIndex, $persist);
        $show->refresh();

        $show->feedState()->updateOrCreate([], [
            'state' => 'pending',
            'consecutive_failures' => 0,
            'etag' => null,
            'last_modified' => null,
            'channel_synced_at' => null,
            'last_error' => null,
            'next_poll_at' => now(),
        ]);

        if ($this->option('sync')) {
            $this->info("Hydrating {$show->title} ({$show->id}) synchronously…");
            dispatch_sync(new HydrateRssFeed($show->id));
        } else {
            HydrateRssFeed::dispatch($show->id);
            $this->info("Queued HydrateRssFeed for {$show->title} ({$show->id}) on the rss queue.");
            $this->line('Ensure Horizon is running: php artisan horizon:status');
        }

        $show->refresh();
        $count = $show->episodes()->count();
        $state = $show->feedState()->first();
        $this->line("Title: {$show->title}");
        $this->line("RSS URL: {$show->rss_url}");
        $this->line('Artwork: '.($show->artwork_url ?: '(none)'));
        $this->line("Episodes now in DB: {$count}");
        $this->line('Feed state: '.($state?->state ?? 'none'));
        if (filled($state?->last_error)) {
            $this->warn('Last feed error: '.$state->last_error);
        }

        return self::SUCCESS;
    }

    private function findShow(string $needle): ?Show
    {
        if (strlen($needle) === 26) {
            return Show::query()->whereKey($needle)->first();
        }

        $exact = Show::query()->whereRaw('LOWER(title) = ?', [mb_strtolower($needle)])->first();
        if ($exact) {
            return $exact;
        }

        $matches = Show::query()
            ->where('title', 'like', '%'.$needle.'%')
            ->orderBy('title')
            ->limit(6)
            ->get();
        if ($matches->count() > 1) {
            $this->warn('Multiple shows matched. Using the first:');
            foreach ($matches as $match) {
                $this->line("  - {$match->title} ({$match->id})");
            }
        }

        return $matches->first();
    }

    private function refreshFromPodcastIndex(Show $show, PodcastIndexClient $client, PersistDiscoveredShow $persist): void
    {
        if (! config('services.podcast_index.enabled')) {
            return;
        }

        $feedId = DB::table('show_external_ids')
            ->where('show_id', $show->id)
            ->where('provider', 'podcast_index')
            ->value('external_id');
        if (! is_string($feedId) || $feedId === '') {
            return;
        }

        try {
            $payload = $client->podcastByFeedId($feedId, useCache: false);
            $feed = $payload['feed'] ?? null;
            if (! is_array($feed)) {
                return;
            }
            $persist->handle($feed);
        } catch (PodcastIndexException $exception) {
            $this->warn('Podcast Index metadata refresh skipped: '.$exception->getMessage());
        }
    }
}
