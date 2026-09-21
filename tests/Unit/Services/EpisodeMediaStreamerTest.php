<?php

namespace Tests\Unit\Services;

use App\Integrations\Rss\FeedUrlGuard;
use App\Services\EpisodeMediaStreamer;
use App\Support\PelevoHttpUserAgent;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EpisodeMediaStreamerTest extends TestCase
{
    public function test_identifies_pelevo_when_proxying_publisher_audio(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://cdn.example.test/shift-life.mp3' => Http::response('ID3audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $guard = new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        (new EpisodeMediaStreamer($guard))->stream('https://cdn.example.test/shift-life.mp3', null);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'https://cdn.example.test/shift-life.mp3'
                && $request->hasHeader('User-Agent', PelevoHttpUserAgent::crawler());
        });
    }
}
