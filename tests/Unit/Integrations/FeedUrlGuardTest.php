<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Rss\FeedUrlGuard;
use App\Integrations\Rss\UnsafeFeedUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FeedUrlGuardTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_rejects_private_local_and_unsupported_feed_urls(string $url): void
    {
        $this->expectException(UnsafeFeedUrlException::class);
        (new FeedUrlGuard)->ensureSafe($url);
    }

    public static function unsafeUrls(): array
    {
        return ['http scheme' => ['http://example.com/feed'], 'loopback' => ['https://127.0.0.1/feed'], 'private v4' => ['https://10.0.0.2/feed'], 'link local' => ['https://169.254.169.254/latest/meta-data'], 'localhost' => ['https://localhost/feed'], 'credentials' => ['https://user:pass@example.com/feed']];
    }

    public function test_resolves_public_hosts_with_separate_dns_lookups(): void
    {
        $guard = new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return $host === 'media.rss.com' ? ['104.18.1.1'] : parent::resolve($host);
            }
        };

        $guard->ensureSafe('https://media.rss.com/wardrobe-memo/feed.xml');
        $this->assertTrue(true);
    }
}
