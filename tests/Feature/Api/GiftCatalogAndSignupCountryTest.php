<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class GiftCatalogAndSignupCountryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_gift_catalog_lists_sendable_gifts(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/gifts/catalog')
            ->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Applause', 'Love', 'Star', 'Champion', 'Crown', 'Legend'], $names);
        $this->assertSame(20, $response->json('data.0.coins'));
        $this->assertSame('applause', $response->json('data.0.slug'));
    }

    public function test_register_stores_the_country_from_signup(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Listener',
            'email' => 'ada-country@example.test',
            'password' => 'password-password',
            'password_confirmation' => 'password-password',
            'device_name' => 'Pixel',
            'country_code' => 'ng',
        ])->assertCreated();

        $this->assertSame('NG', User::where('email', 'ada-country@example.test')->value('country_code'));
    }

    public function test_register_uses_the_connection_country_when_the_device_does_not_send_one(): void
    {
        $this->withHeader('X-Test-Country', 'GH')->postJson('/api/v1/auth/register', [
            'name' => 'Kofi Listener',
            'email' => 'kofi-country@example.test',
            'password' => 'password-password',
            'password_confirmation' => 'password-password',
            'device_name' => 'Pixel',
        ])->assertCreated();

        $this->assertSame('GH', User::where('email', 'kofi-country@example.test')->value('country_code'));
    }
}
