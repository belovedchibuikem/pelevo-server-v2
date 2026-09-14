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
                'title' => $this->nonEmptyTitle($feed['title'] ?? null),
                'description' => strip_tags((string) ($feed['description'] ?? '')),
                'artwork_url' => ArtworkUrl::sanitize($feed['image'] ?? $feed['artwork'] ?? null),
                'author' => $this->nullableAuthor($feed['author'] ?? null),
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

            $this->linkPodcastIndexCategories($show->id, $feed['categories'] ?? null);

            return $show;
        }, 3);
    }

    private function nonEmptyTitle(mixed $value): string
    {
        $title = trim(strip_tags((string) ($value ?? '')));

        return $title !== '' ? $title : 'Untitled podcast';
    }

    private function nullableAuthor(mixed $value): ?string
    {
        $author = trim(strip_tags((string) ($value ?? '')));

        return $author !== '' ? $author : null;
    }

    private function linkPodcastIndexCategories(string $showId, mixed $categories): void
    {
        if (! is_array($categories) || $categories === []) {
            return;
        }

        foreach ($categories as $piId => $name) {
            $id = is_numeric($piId) ? (int) $piId : 0;
            if ($id <= 0) {
                continue;
            }
            $categoryId = DB::table('categories')->where('podcast_index_id', $id)->value('id');
            if (! $categoryId) {
                continue;
            }
            DB::table('category_show')->insertOrIgnore([
                'category_id' => $categoryId,
                'show_id' => $showId,
            ]);
        }
    }
}
