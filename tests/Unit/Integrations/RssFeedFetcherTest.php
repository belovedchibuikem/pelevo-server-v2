<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Rss\FeedUrlGuard;
use App\Integrations\Rss\RssFeedFetcher;
use App\Models\Show;
use App\Models\ShowFeedState;
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
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->hasHeader('User-Agent', (string) config('rss.user_agent')));
    }

    public function test_follows_permanent_redirects_and_reports_the_canonical_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://feeds.example.test/old.xml' => Http::response('', 301, ['Location' => 'https://cdn.example.test/new.xml']),
            'https://cdn.example.test/new.xml' => Http::response('<?xml version="1.0"?><rss><channel><title>Moved</title></channel></rss>', 200),
        ]);
        $guard = new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $show = new Show(['rss_url' => 'https://feeds.example.test/old.xml', 'title' => 'Show']);
        $show->setRelation('feedState', null);

        $result = (new RssFeedFetcher($guard))->fetch($show);

        $this->assertTrue($result['permanent_redirect']);
        $this->assertSame('https://cdn.example.test/new.xml', $result['canonical_url']);
        $this->assertSame('https://cdn.example.test/new.xml', $result['resolved_url']);
        $this->assertSame('Moved', (string) $result['xml']->channel->title);
        Http::assertSentCount(2);
    }

    public function test_follows_temporary_redirects_without_changing_the_canonical_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://feeds.example.test/show.xml' => Http::response('', 302, ['Location' => 'https://cdn.example.test/tmp.xml']),
            'https://cdn.example.test/tmp.xml' => Http::response('<?xml version="1.0"?><rss><channel><title>Temp</title></channel></rss>', 200),
        ]);
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

        $this->assertFalse($result['permanent_redirect']);
        $this->assertSame('https://feeds.example.test/show.xml', $result['canonical_url']);
        $this->assertSame('https://cdn.example.test/tmp.xml', $result['resolved_url']);
        Http::assertSentCount(2);
    }

    public function test_follows_308_permanent_redirects(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://feeds.example.test/old.xml' => Http::response('', 308, ['Location' => '/new.xml']),
            'https://feeds.example.test/new.xml' => Http::response('<?xml version="1.0"?><rss><channel><title>Relocated</title></channel></rss>', 200),
        ]);
        $guard = new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $show = new Show(['rss_url' => 'https://feeds.example.test/old.xml', 'title' => 'Show']);
        $show->setRelation('feedState', null);

        $result = (new RssFeedFetcher($guard))->fetch($show);

        $this->assertTrue($result['permanent_redirect']);
        $this->assertSame('https://feeds.example.test/new.xml', $result['canonical_url']);
        $this->assertSame('Relocated', (string) $result['xml']->channel->title);
    }

    public function test_does_not_reuse_etag_after_a_permanent_redirect(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() === 'https://feeds.example.test/old.xml') {
                return Http::response('', 301, ['Location' => 'https://cdn.example.test/new.xml']);
            }
            if ($request->hasHeader('If-None-Match') || $request->hasHeader('If-Modified-Since')) {
                return Http::response('', 304);
            }

            return Http::response('<?xml version="1.0"?><rss><channel><title>Moved Host</title></channel></rss>', 200);
        });
        $guard = new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $show = new Show(['rss_url' => 'https://feeds.example.test/old.xml', 'title' => 'Show']);
        $show->setRelation('feedState', new ShowFeedState([
            'etag' => '"stale-from-old-host"',
            'last_modified' => 'Wed, 01 Jan 2020 00:00:00 GMT',
        ]));

        $result = (new RssFeedFetcher($guard))->fetch($show);

        $this->assertFalse($result['not_modified']);
        $this->assertTrue($result['permanent_redirect']);
        $this->assertSame('https://cdn.example.test/new.xml', $result['canonical_url']);
        $this->assertSame('Moved Host', (string) $result['xml']->channel->title);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'https://cdn.example.test/new.xml'
                && ! $request->hasHeader('If-None-Match')
                && ! $request->hasHeader('If-Modified-Since');
        });
    }
}
