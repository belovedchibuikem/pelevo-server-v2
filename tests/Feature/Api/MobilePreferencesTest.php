<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MobilePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_require_authentication(): void
    {
        $this->getJson('/api/v1/me/preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/me/preferences', ['version' => 0, 'data_saver' => true])->assertUnauthorized();
        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_server_defaults_do_not_opt_users_into_optional_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('data.user_id', $user->id)->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.email_product', false)->assertJsonPath('data.email_episodes', false)
            ->assertJsonPath('data.email_marketing', false)->assertJsonPath('data.audio_quality', 'high')
            ->assertJsonPath('data.data_saver', false);
        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_saves_typed_preferences_only_for_the_authenticated_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/me/preferences', ['version' => 0, 'user_id' => $other->id, 'email_product' => true, 'email_episodes' => true, 'email_marketing' => false, 'data_saver' => true, 'audio_quality' => 'low'])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.email_product', true)
            ->assertJsonPath('data.data_saver', true)->assertJsonPath('data.audio_quality', 'low');
        $this->assertDatabaseHas('user_preferences', ['user_id' => $owner->id, 'version' => 1, 'email_product' => true, 'email_episodes' => true, 'email_marketing' => false, 'data_saver' => true, 'audio_quality' => 'low']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $other->id]);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/me/preferences')->assertJsonPath('data.email_product', false);
    }

    public function test_partial_updates_preserve_other_settings_and_stale_writes_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->patchJson('/api/v1/me/preferences', ['version' => 0, 'email_product' => true, 'data_saver' => true])->assertOk();

        $this->patchJson('/api/v1/me/preferences', ['version' => 1, 'audio_quality' => 'very_high'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.email_product', true)->assertJsonPath('data.data_saver', true);
        $this->patchJson('/api/v1/me/preferences', ['version' => 1, 'email_product' => false])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertDatabaseHas('user_preferences', ['version' => 2, 'email_product' => true, 'audio_quality' => 'very_high']);
    }

    #[DataProvider('invalidPreferences')]
    public function test_invalid_preferences_are_not_persisted(array $payload, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->patchJson('/api/v1/me/preferences', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION')->assertJsonStructure(['error' => ['fields' => [$field]]]);
        $this->assertDatabaseCount('user_preferences', 0);
    }

    public static function invalidPreferences(): array
    {
        return [
            'version missing' => [['data_saver' => true], 'version'],
            'version negative' => [['version' => -1], 'version'],
            'version fractional' => [['version' => 1.5], 'version'],
            'invalid quality' => [['version' => 0, 'audio_quality' => 'lossless'], 'audio_quality'],
            'invalid saver' => [['version' => 0, 'data_saver' => 'yes'], 'data_saver'],
            'invalid product' => [['version' => 0, 'email_product' => 'yes'], 'email_product'],
            'invalid episodes' => [['version' => 0, 'email_episodes' => null], 'email_episodes'],
            'invalid marketing' => [['version' => 0, 'email_marketing' => 'yes'], 'email_marketing'],
        ];
    }
}
