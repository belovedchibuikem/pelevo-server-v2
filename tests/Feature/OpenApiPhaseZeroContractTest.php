<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

final class OpenApiPhaseZeroContractTest extends TestCase
{
    public function test_every_phase_zero_mobile_route_and_method_is_in_openapi(): void
    {
        $contract = file_get_contents(base_path('openapi.yaml'));
        $expected = [
            'auth/register' => ['POST'], 'auth/login' => ['POST'], 'auth/social' => ['POST'], 'auth/otp/send' => ['POST'], 'auth/otp/verify' => ['POST'], 'auth/password/forgot' => ['POST'], 'auth/password/reset' => ['POST'], 'auth/refresh' => ['POST'], 'auth/logout' => ['POST'], 'me' => ['GET', 'PATCH'], 'me/password' => ['PATCH'], 'me/onboarding' => ['PUT'], 'me/preferences' => ['GET', 'PATCH'], 'me/connected-accounts' => ['GET', 'POST'], 'me/connected-accounts/{account}' => ['DELETE'], 'me/invite' => ['GET', 'POST'], 'referrals/redeem' => ['POST'], 'me/data-export' => ['POST'], 'me/delete' => ['POST'], 'me/config' => ['GET'],
        ];
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))->groupBy(fn (Route $route): string => substr($route->uri(), 7));

        foreach ($expected as $path => $methods) {
            $this->assertStringContainsString("  /{$path}:", $contract, "OpenAPI path /{$path} is missing.");
            $actual = $routes->get($path, collect())->flatMap(fn (Route $route): array => array_values(array_diff($route->methods(), ['HEAD'])))->unique();
            foreach ($methods as $method) {
                $this->assertTrue($actual->contains($method), "Application route {$method} /{$path} is missing.");
                $pathBlock = explode("\n  /", explode("  /{$path}:\n", $contract, 2)[1], 2)[0];
                $this->assertMatchesRegularExpression('/^    '.strtolower($method).':/m', $pathBlock, "OpenAPI method {$method} /{$path} is missing.");
            }
        }
    }
}
