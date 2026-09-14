<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrowsePodcastIndexCategoriesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_browse_syncs_podcast_index_categories_and_hydrates_shows(): void
    {
        Cache::flush();
        config()->set([
            'services.podcast_index.enabled' => true,
            'services.podcast_index.api_key' => 'test-key',
            'services.podcast_index.api_secret' => 'test-secret',
            'services.podcast_index.base_url' => 'https://api.podcastindex.org/api/1.0',
        ]);

        Http::fake([
            'api.podcastindex.org/api/1.0/categories/list*' => Http::response([
                'status' => 'true',
                'count' => 2,
                'feeds' => [
                    ['id' => 55, 'name' => 'Technology'],
                    ['id' => 77, 'name' => 'News'],
                ],
            ]),
            'api.podcastindex.org/api/1.0/podcasts/trending*' => Http::response([
                'status' => 'true',
                'count' => 1,
                'feeds' => [[
                    'id' => 9001,
                    'url' => 'https://example.com/tech.xml',
                    'title' => 'Tech Daily',
                    'author' => 'Tech Host',
                    'image' => 'https://example.com/tech.png',
                    'language' => 'en',
                    'categories' => ['55' => 'Technology'],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $client = $this->actingAs($user, 'sanctum');

        $browse = $client->getJson('/api/v1/browse')->assertOk();
        $this->assertGreaterThanOrEqual(2, count($browse->json('data')));
        $this->assertTrue(collect($browse->json('data'))->contains(fn (array $row): bool => $row['slug'] === 'technology'));

        $detail = $client->getJson('/api/v1/browse/technology')->assertOk();
        $detail->assertJsonPath('data.category.slug', 'technology');
        $detail->assertJsonPath('data.shows.0.title', 'Tech Daily');
        $detail->assertJsonMissingPath('data.shows.0.rss_url');
    }

    public function test_unknown_category_slug_returns_not_found_when_taxonomy_missing(): void
    {
        Cache::flush();
        config()->set('services.podcast_index.enabled', false);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/browse/'.Str::slug('missing-category'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
