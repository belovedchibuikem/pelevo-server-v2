<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

final class OpenApiPhaseTwoContractTest extends TestCase
{
    public function test_every_phase_two_mobile_route_and_method_is_in_openapi(): void
    {
        $contract = file_get_contents(base_path('openapi.yaml'));
        $expected = ['shows/{show}/claims' => ['POST'], 'claims/{claim}' => ['GET'], 'claims/{claim}/verify' => ['POST'], 'claims/{claim}/challenge' => ['POST'], 'claims/{claim}/description/confirm' => ['POST'], 'studio' => ['GET'], 'studio/shows' => ['GET'], 'studio/episodes' => ['GET', 'POST'], 'studio/reels' => ['GET'], 'studio/reels/{reel}' => ['GET'], 'studio/audience' => ['GET'], 'studio/audience/followers' => ['GET'], 'studio/audience/supporters' => ['GET'], 'studio/monetization' => ['GET'], 'studio/transactions' => ['GET'], 'studio/transactions/{transaction}' => ['GET'], 'studio/payout-settings' => ['GET', 'PUT'], 'studio/live' => ['POST'], 'studios' => ['GET', 'POST'], 'studios/{studio}' => ['GET', 'PUT', 'DELETE'], 'studios/{studio}/members' => ['POST']];
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
