<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OpenApiPhaseFiveContractTest extends TestCase
{
    public function test_phase_five_mobile_routes_match_openapi_methods(): void
    {
        $expected = ['premium/plans' => ['GET'], 'premium/me' => ['GET'], 'premium/invoices' => ['GET'], 'premium/exclusive' => ['GET'], 'premium/checkout' => ['POST'], 'premium/change-plan' => ['POST'], 'premium/restore' => ['POST'], 'studio/payout-settings' => ['GET', 'PUT']];
        $spec = file_get_contents(base_path('openapi.yaml'));
        foreach ($expected as $path => $methods) {
            $this->assertStringContainsString('/'.$path.':', $spec);
            $routeMethods = collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === 'api/v1/'.$path)->flatMap(fn ($route) => $route->methods())->unique()->all();
            $this->assertNotEmpty($routeMethods, 'Missing route '.$path);
            foreach ($methods as $method) {
                $this->assertContains($method, $routeMethods, $path.' missing '.$method);
            }
        }
    }
}
