<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

final class OpenApiPhaseThreeContractTest extends TestCase
{
    public function test_every_phase_three_mobile_route_and_method_is_in_openapi(): void
    {
        $contract = file_get_contents(base_path('openapi.yaml'));
        $expected = ['comments' => ['GET', 'POST'], 'comments/{comment}' => ['PATCH', 'DELETE'], 'comments/{comment}/like' => ['POST', 'DELETE'], 'comments/{comment}/pin' => ['PUT', 'DELETE'], 'comments/{comment}/hide' => ['PUT'], 'reports' => ['POST'], 'appeals' => ['GET', 'POST'], 'creators/{creator}/follow' => ['POST', 'DELETE'], 'shows/{show}/follow' => ['POST', 'DELETE'], 'reels/for-you' => ['GET'], 'reels/following' => ['GET'], 'reels/trending' => ['GET'], 'reels/saved' => ['GET'], 'reels/{reel}' => ['GET', 'DELETE'], 'reels/{reel}/related-show' => ['GET'], 'reels/{reel}/like' => ['POST', 'DELETE'], 'reels/{reel}/save' => ['POST', 'DELETE'], 'reels/{reel}/not-interested' => ['POST'], 'reels/{reel}/episode-links' => ['POST'], 'reels/{reel}/view-heartbeat' => ['POST'], 'reels/monetization' => ['GET'], 'reels/monetization/opt-in' => ['POST'], 'live-sessions' => ['GET', 'POST'], 'live-sessions/{session}/state' => ['PUT'], 'live-sessions/{session}/health' => ['POST'], 'studio/live' => ['POST'], 'studio/live/{session}' => ['PATCH'], 'studio/live/{session}/health' => ['POST'], 'uploads/{upload}/content' => ['PUT', 'POST']];
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))->groupBy(fn (Route $route): string => substr($route->uri(), 7));
        foreach ($expected as $path => $methods) {
            $this->assertStringContainsString("  /{$path}:", $contract);
            $actual = $routes->get($path, collect())->flatMap(fn (Route $route): array => array_values(array_diff($route->methods(), ['HEAD'])))->unique();
            foreach ($methods as $method) {
                $this->assertTrue($actual->contains($method), "Application route {$method} /{$path} is missing.");
                $block = explode("\n  /", explode("  /{$path}:\n", $contract, 2)[1], 2)[0];
                $this->assertMatchesRegularExpression('/^    '.strtolower($method).':/m', $block);
            }
        }
    }
}
