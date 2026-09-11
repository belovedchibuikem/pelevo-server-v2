<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_issues_device_bound_rotating_tokens(): void
    {
        $response = $this->withHeaders(['X-Device-Id' => 'flutter-test-device'])->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Ada phone']);
        $response->assertCreated()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'device_id'], 'request_id']);
        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $response->json('data.refresh_token')]);
    }

    public function test_validation_uses_stable_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION')->assertJsonPath('ok', false);
    }

    public function test_registration_saves_username_and_rejects_duplicates(): void
    {
        User::factory()->create(['handle' => 'taken_name']);
        $payload = ['name' => 'Ada', 'handle' => 'taken_name', 'email' => 'new-listener@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Phone'];

        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION');
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);

        $payload['handle'] = 'new_listener';
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated()->assertJsonPath('data.user.handle', 'new_listener');
        $this->assertDatabaseHas('users', ['email' => $payload['email'], 'handle' => 'new_listener']);
    }

    public function test_mobile_token_reaches_me(): void
    {
        $registration = $this->withHeaders(['X-Device-Id' => 'test-device'])->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'phone']);
        $this->withToken($registration->json('data.access_token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    }
}
