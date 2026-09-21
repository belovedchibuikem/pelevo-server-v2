<?php

namespace Tests\Unit\Support;

use App\Support\PelevoHttpUserAgent;
use Tests\TestCase;

final class PelevoHttpUserAgentTest extends TestCase
{
    public function test_crawler_identity_is_pelevo_and_not_a_generic_client(): void
    {
        $ua = PelevoHttpUserAgent::crawler();

        $this->assertSame('Pelevo/1.0.0 (podcast-sync; +https://pelevo.com)', $ua);
        $this->assertStringStartsWith('Pelevo/', $ua);
        $this->assertStringContainsString('+https://pelevo.com', $ua);
        $this->assertStringNotContainsString('GuzzleHttp', $ua);
        $this->assertStringNotContainsString('Mozilla/', $ua);
    }

    public function test_falls_back_when_config_is_blank(): void
    {
        config()->set('rss.user_agent', '   ');

        $this->assertSame(PelevoHttpUserAgent::DEFAULT_CRAWLER, PelevoHttpUserAgent::crawler());
    }
}
