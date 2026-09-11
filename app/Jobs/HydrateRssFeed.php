<?php

namespace App\Jobs;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Events\NewEpisodePublished;
use App\Integrations\Rss\RssFeedFetcher;
use App\Models\Episode;
use App\Models\FeedSyncRun;
use App\Models\Show;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class HydrateRssFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $showId)
    {
        $this->onQueue('rss');
    }

    public function uniqueId(): string
    {
        return $this->showId;
    }

    public function handle(RssFeedFetcher $fetcher, InvalidateDiscoveryCache $cache): void
    {
        $show = Show::with('feedState')->findOrFail($this->showId);
        $syncRun = FeedSyncRun::create([
            'show_id' => $show->id,
            'state' => 'running',
            'started_at' => now(),
        ]);

        try {
            $result = $fetcher->fetch($show);
            if ($result['not_modified']) {
                DB::transaction(function () use ($show, $syncRun, $result): void {
                    $show->feedState()->updateOrCreate([], ['last_success_at' => now(), 'consecutive_failures' => 0, 'state' => 'healthy', 'next_poll_at' => now()->addHour(), 'last_error' => null]);
                    $syncRun->update(['state' => 'not_modified', 'resolved_url' => $result['resolved_url'], 'http_status' => 304, 'finished_at' => now()]);
                });

                return;
            }

            $newEpisodes = DB::transaction(function () use ($show, $result, $syncRun): array {
                $this->applyResolvedUrl($show, $result['resolved_url']);
                $newEpisodes = [];
                $channel = $result['xml']->channel ?? $result['xml'];
                foreach ($channel->item ?? [] as $item) {
                    $guid = trim((string) ($item->guid ?? '')) ?: hash('sha256', trim((string) $item->title).'|'.trim((string) ($item->enclosure['url'] ?? '')).'|'.trim((string) $item->pubDate));
                    $audioUrl = filter_var((string) ($item->enclosure['url'] ?? ''), FILTER_VALIDATE_URL);
                    if (! $audioUrl || ! str_starts_with($audioUrl, 'https://')) {
                        continue;
                    }
                    $episode = Episode::updateOrCreate(['show_id' => $show->id, 'guid' => $guid], ['title' => strip_tags((string) $item->title), 'description' => strip_tags((string) ($item->description ?? '')), 'audio_url' => $audioUrl, 'duration_seconds' => $this->durationSeconds($item), 'published_at' => ($date = strtotime((string) $item->pubDate)) ? date(DATE_ATOM, $date) : null, 'availability' => 'available']);
                    if ($episode->wasRecentlyCreated) {
                        $newEpisodes[] = $episode;
                    }
                }
                $show->feedState()->updateOrCreate([], ['etag' => $result['etag'], 'last_modified' => $result['last_modified'], 'content_hash' => $result['content_hash'], 'last_success_at' => now(), 'consecutive_failures' => 0, 'state' => 'healthy', 'next_poll_at' => now()->addHour(), 'last_error' => null]);
                $syncRun->update(['state' => 'completed', 'resolved_url' => $result['resolved_url'], 'http_status' => $result['status'], 'new_episode_count' => count($newEpisodes), 'finished_at' => now()]);

                return $newEpisodes;
            });
            foreach ($newEpisodes as $episode) {
                NewEpisodePublished::dispatch($episode);
            }
            if ($newEpisodes !== []) {
                $cache->show($show->id);
            }
        } catch (Throwable $exception) {
            $syncRun->update(['state' => 'failed', 'error' => substr($exception->getMessage(), 0, 1000), 'finished_at' => now()]);

            throw $exception;
        }
    }

    private function applyResolvedUrl(Show $show, string $resolvedUrl): void
    {
        if ($resolvedUrl === $show->rss_url) {
            return;
        }

        $conflict = Show::query()
            ->where('rss_url_hash', hash('sha256', $resolvedUrl))
            ->whereKeyNot($show->id)
            ->exists();

        DB::table('show_metadata_changes')->insert([
            'id' => (string) Str::ulid(),
            'show_id' => $show->id,
            'field' => $conflict ? 'rss_url_redirect_conflict' : 'rss_url',
            'old_value' => $show->rss_url,
            'new_value' => $resolvedUrl,
            'detected_at' => now(),
        ]);

        if (! $conflict) {
            $show->update(['rss_url' => $resolvedUrl]);
        }
    }

    private function durationSeconds(\SimpleXMLElement $item): ?int
    {
        $duration = trim((string) $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd')->duration);
        if ($duration === '') {
            return null;
        }
        if (ctype_digit($duration)) {
            return (int) $duration;
        }

        $parts = array_map('intval', explode(':', $duration));
        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }
        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return null;
    }

    public function failed(?Throwable $exception): void
    {
        $show = Show::find($this->showId);
        if (! $show) {
            return;
        }
        $state = $show->feedState()->firstOrCreate([], ['state' => 'failed']);
        $failures = $state->consecutive_failures + 1;
        $state->update(['last_failure_at' => now(), 'consecutive_failures' => $failures, 'state' => $failures >= config('rss.failure_stale_threshold') ? 'stale' : 'failed', 'next_poll_at' => $failures >= config('rss.failure_stale_threshold') ? now()->addWeek() : now()->addHours(min(24, 2 ** $failures)), 'last_error' => substr((string) $exception?->getMessage(), 0, 1000)]);
    }
}
