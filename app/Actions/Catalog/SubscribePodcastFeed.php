<?php

namespace App\Actions\Catalog;

use App\Integrations\Rss\FeedAddress;
use App\Integrations\Rss\FeedProbe;
use App\Integrations\Rss\UnsafeFeedUrlException;
use App\Jobs\HydrateRssFeed;
use App\Models\Show;
use Illuminate\Support\Facades\DB;

/**
 * Persists one directory feed into the shared catalog. The same feed from
 * Apple and Podcast Index resolves to the show that already owns that URL.
 * A dead feed is refused. Hydration is queued so later episodes keep arriving
 * through the existing RSS poll.
 */
final class SubscribePodcastFeed
{
    public function __construct(
        private readonly PersistDiscoveredShow $persist,
        private readonly FeedProbe $probe,
        private readonly DirectoryRateGate $gate,
    ) {}

    /**
     * @param  array{source: string, feed_url: string, title: string, external_id?: ?string, author?: ?string}  $input
     * @return array{show: Show, already_in_catalog: bool}
     */
    public function handle(array $input): array
    {
        $address = FeedAddress::tryParse($input['feed_url']);
        if ($address === null) {
            throw new UnsafeFeedUrlException('That feed address can’t be added.');
        }

        $source = $input['source'];
        $externalId = trim((string) ($input['external_id'] ?? ''));
        if ($externalId === '') {
            $externalId = substr(hash('sha256', $address->key), 0, 32);
        }

        $https = $address->httpsUrl();
        $existing = $this->findExisting($https, $source, $externalId);
        if ($existing instanceof Show) {
            DB::table('show_external_ids')->insertOrIgnore([
                'show_id' => $existing->id,
                'provider' => $source,
                'external_id' => $externalId,
            ]);

            return ['show' => $existing, 'already_in_catalog' => true];
        }

        if (! $this->gate->acquire('probe')) {
            throw $this->gate->blocked();
        }

        $probed = $this->probe->inspect($https);
        if ($probed['health'] === 'dead') {
            throw new DeadFeedException($probed['message'] ?? 'This podcast feed is unavailable, so it can’t be added.');
        }
        if ($probed['health'] !== 'alive' && $source === 'rss') {
            throw new DeadFeedException($probed['message'] ?? 'This podcast feed didn’t respond. Try again in a moment.');
        }

        $title = trim($input['title']);
        if ($title === '') {
            $title = $probed['title'] ?: 'Podcast';
        }

        $show = $this->persist->handle([
            'id' => $externalId,
            'url' => $https,
            'title' => $title,
            'author' => $input['author'] ?? $probed['author'] ?? null,
            'description' => '',
            'image' => $probed['artwork'] ?? null,
        ], $source);

        if (! $show instanceof Show) {
            throw new DeadFeedException('That podcast feed could not be added.');
        }

        $show->feedState()->updateOrCreate([], [
            'state' => 'pending',
            'next_poll_at' => now(),
            'last_error' => null,
        ]);
        HydrateRssFeed::dispatchAfterResponse($show->id);

        return ['show' => $show, 'already_in_catalog' => false];
    }

    private function findExisting(string $httpsUrl, string $source, string $externalId): ?Show
    {
        $showId = DB::table('show_external_ids')
            ->where('provider', $source)
            ->where('external_id', $externalId)
            ->value('show_id');

        if (is_string($showId) && $showId !== '') {
            $show = Show::query()->find($showId);
            if ($show instanceof Show) {
                return $show;
            }
        }

        $address = FeedAddress::tryParse($httpsUrl);
        $hashes = [hash('sha256', $httpsUrl)];
        if ($address !== null) {
            $hashes[] = hash('sha256', $address->httpsUrl());
        }

        return Show::query()->whereIn('rss_url_hash', array_values(array_unique($hashes)))->first();
    }
}
