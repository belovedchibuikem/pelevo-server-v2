<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Rss\FeedUrlGuard;
use App\Integrations\Rss\RssFeedFetcher;
use App\Models\Show;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RssFeedFetcherTest extends TestCase
{
    public function test_fetches_with_redirects_disabled_and_parses_bounded_xml(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://feeds.example.test/show.xml' => Http::response('<?xml version="1.0"?><rss><channel><title>Show</title></channel></rss>', 200, ['ETag' => 'v1'])]);
        $guard = new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $show = new Show(['rss_url' => 'https://feeds.example.test/show.xml', 'title' => 'Show']);
        $show->setRelation('feedState', null);

        $result = (new RssFeedFetcher($guard))->fetch($show);

        $this->assertFalse($result['not_modified']);
        $this->assertSame('v1', $result['etag']);
        $this->assertSame('Show', (string) $result['xml']->channel->title);
        Http::assertSentCount(1);
    }
}
