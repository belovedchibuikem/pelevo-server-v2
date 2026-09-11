<?php

namespace Tests\Feature\Api;

use App\Models\ConfigurationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_is_versioned_and_supports_etags(): void
    {
        ConfigurationVersion::create(['version' => 1, 'payload' => ['flags' => ['earn_enabled' => true]], 'reason' => 'test', 'effective_at' => now()]);
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/config')->assertOk()->assertHeader('ETag', '"config-1"')->assertJsonPath('data.version', 1);
        $this->actingAs($user, 'sanctum')->withHeader('If-None-Match', $response->headers->get('ETag'))->getJson('/api/v1/me/config')->assertStatus(304);
    }
}
