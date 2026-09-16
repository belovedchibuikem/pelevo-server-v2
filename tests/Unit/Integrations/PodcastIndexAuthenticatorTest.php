<?php

namespace Tests\Unit\Integrations;

use App\Integrations\PodcastIndex\PodcastIndexAuthenticator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class PodcastIndexAuthenticatorTest extends TestCase
{
    public function test_generates_official_sha1_header_contract(): void
    {
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');
        config()->set('services.podcast_index.user_agent', 'Pelevo/1.3 +https://pelevo.com');
        $headers = (new PodcastIndexAuthenticator)->headers(CarbonImmutable::createFromTimestampUTC(1700000000));
        $this->assertSame('key', $headers['X-Auth-Key']);
        $this->assertSame('1700000000', $headers['X-Auth-Date']);
        $this->assertSame(sha1('keysecret1700000000'), $headers['Authorization']);
        $this->assertSame('Pelevo/1.3 +https://pelevo.com', $headers['User-Agent']);
    }
}
