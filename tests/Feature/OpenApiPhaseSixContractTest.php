<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OpenApiPhaseSixContractTest extends TestCase
{
    public function test_phase_six_mobile_routes_match_openapi_methods(): void
    {
        $expected = ['downloads/{download}' => ['DELETE'], 'devices' => ['GET'], 'devices/pairing-code' => ['POST'], 'devices/pair' => ['POST'], 'devices/{device}' => ['DELETE'], 'devices/sign-out-all' => ['POST'], 'sync/push' => ['POST'], 'sync/pull' => ['GET'], 'sync/conflicts' => ['GET'], 'sync/conflicts/{conflict}' => ['PUT'], 'backups' => ['POST'], 'backups/{backup}/restore' => ['POST'], 'storage' => ['GET'], 'storage/cache' => ['DELETE'], 'share-card-templates' => ['GET'], 'share-cards' => ['GET', 'POST'], 'share-cards/{card}' => ['GET'], 'notification-lock-rules' => ['GET', 'PUT'], 'me/push-tokens' => ['POST', 'DELETE'], 'ai/jobs' => ['POST'], 'ai/jobs/{job}' => ['GET'], 'stats/me' => ['GET'], 'home/recommendations' => ['GET'], 'search' => ['GET'], 'search/recent' => ['GET', 'POST', 'DELETE'], 'search/voice' => ['POST'], 'browse' => ['GET'], 'browse/categories' => ['GET'], 'browse/{slug}' => ['GET'], 'cms/help' => ['GET'], 'cms/about' => ['GET'], 'cms/guidelines' => ['GET'], 'support/tickets' => ['POST'], 'support/feedback' => ['POST']];
        $spec = file_get_contents(base_path('openapi.yaml'));
        foreach ($expected as $path => $methods) {
            $this->assertStringContainsString('/'.$path.':', $spec);
            $actual = collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === 'api/v1/'.$path)->flatMap(fn ($route) => $route->methods())->unique()->all();
            foreach ($methods as $method) {
                $this->assertContains($method, $actual, $path.' missing '.$method);
            }
        }
    }
}
