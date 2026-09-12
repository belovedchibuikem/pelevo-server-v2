<?php

namespace App\Actions\Catalog;

use App\Models\Show;
use App\Support\ArtworkUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PersistDiscoveredShow
{
    public function handle(array $feed, string $provider = 'podcast_index'): ?Show
    {
        $externalId = isset($feed['id']) ? trim((string) $feed['id']) : '';
        $rssUrl = filter_var($feed['url'] ?? $feed['originalUrl'] ?? null, FILTER_VALIDATE_URL);

        if ($externalId === '' || ! $rssUrl) {
            return null;
        }
        if (Str::startsWith($rssUrl, 'http://')) {
            $rssUrl = 'https://'.substr($rssUrl, strlen('http://'));
        }
        if (! Str::startsWith($rssUrl, 'https://')) {
            return null;
        }

        // Persist catalog metadata only. Episode RSS hydration is queued on
        // demand when the listener opens the show (CatalogController).
        return DB::transaction(function () use ($feed, $provider, $externalId, $rssUrl): Show {
            $showId = DB::table('show_external_ids')
                ->where('provider', $provider)
                ->where('external_id', $externalId)
                ->value('show_id');

            $show = $showId ? Show::find($showId) : null;
            $show ??= Show::where('rss_url_hash', hash('sha256', $rssUrl))->first();
            $show ??= new Show(['rss_url' => $rssUrl]);

            $show->fill([
                'title' => strip_tags((string) ($feed['title'] ?? 'Untitled podcast')),
                'description' => strip_tags((string) ($feed['description'] ?? '')),
                'artwork_url' => ArtworkUrl::sanitize($feed['image'] ?? $feed['artwork'] ?? null),
                'author' => strip_tags((string) ($feed['author'] ?? '')),
                'language' => substr((string) ($feed['language'] ?? ''), 0, 35),
                'country_code' => preg_match('/^[A-Za-z]{2}$/', (string) ($feed['country'] ?? '')) ? strtoupper((string) $feed['country']) : null,
                'explicit' => filter_var($feed['explicit'] ?? false, FILTER_VALIDATE_BOOL) ? 'explicit' : 'clean',
            ]);

            if (! $show->exists) {
                $show->rss_url = $rssUrl;
            }

            $show->save();

            DB::table('show_external_ids')->insertOrIgnore([
                'show_id' => $show->id,
                'provider' => $provider,
                'external_id' => $externalId,
            ]);

            $show->feedState()->firstOrCreate([], [
                'state' => 'pending',
                'consecutive_failures' => 0,
                'next_poll_at' => now(),
            ]);

            return $show;
        }, 3);
    }
}
