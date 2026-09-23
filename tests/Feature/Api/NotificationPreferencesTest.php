<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/notification-preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/notification-preferences', [])->assertUnauthorized();
    }

    public function test_defaults_are_typed_without_invented_schedule_or_delivery(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notification-preferences')->assertOk()
            ->assertJsonPath('data.user_id', $user->id)->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.options.email_enabled', true)->assertJsonPath('data.options.summary_days', [])
            ->assertJsonPath('data.options.summary_time', null)->assertJsonPath('data.delivery_status', 'in_app_policy_enabled');
        $this->assertDatabaseCount('notification_preferences', 0);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['version' => 0, 'new_episodes' => true, 'push_enabled' => false, 'timezone' => 'Africa/Lagos', 'quiet_hours_start' => '22:30', 'quiet_hours_end' => '07:15'], $overrides);
    }

    public function test_saves_owner_settings_and_rejects_stale_versions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner, 'sanctum');

        $this->patchJson('/api/v1/notification-preferences', $this->payload(['user_id' => $other->id, 'options' => ['email_enabled' => true, 'types' => ['mentions'], 'summary_days' => [1, 3], 'summary_time' => '08:00', 'summary_include' => ['mentions']]]))
            ->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.quiet_hours_start', '22:30')->assertJsonPath('data.options.email_enabled', true);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $owner->id, 'version' => 1, 'push_enabled' => false, 'timezone' => 'Africa/Lagos']);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $other->id]);
        $this->patchJson('/api/v1/notification-preferences', $this->payload())->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $owner->id, 'version' => 1]);
        $this->getJson('/api/v1/notification-preferences')->assertJsonPath('data.options.summary_days', [1, 3]);
    }

    public function test_partial_options_preserve_saved_values_and_quiet_hours_can_be_disabled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $this->patchJson('/api/v1/notification-preferences', $this->payload(['options' => ['badge_enabled' => false]]))->assertOk();
        $createdAt = DB::table('notification_preferences')->where('user_id', $user->id)->value('created_at');

        $this->patchJson('/api/v1/notification-preferences', $this->payload(['version' => 1, 'quiet_hours_start' => null, 'quiet_hours_end' => null, 'options' => ['high_priority_only' => true]]))
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.options.badge_enabled', false)->assertJsonPath('data.quiet_hours_start', null);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'version' => 2, 'created_at' => $createdAt, 'quiet_hours_end' => null]);
    }

    public function test_legacy_put_can_update_without_mobile_version(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $payload = $this->payload();
        unset($payload['version']);

        $this->putJson('/api/v1/notification-preferences', $payload)->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseCount('notification_preferences', 1);
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_do_not_persist(array $overrides): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->patchJson('/api/v1/notification-preferences', $this->payload($overrides))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION');
        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public static function invalidSettings(): array
    {
        return [
            'version missing' => [['version' => null]],
            'bad boolean' => [['push_enabled' => 'yes']],
            'bad timezone' => [['timezone' => 'Not/AZone']],
            'bad time' => [['quiet_hours_start' => '27:30']],
            'half quiet window' => [['quiet_hours_end' => null]],
            'equal times' => [['quiet_hours_end' => '22:30']],
            'unknown option' => [['options' => ['admin' => true]]],
            'unknown type' => [['options' => ['types' => ['fake']]]],
            'duplicate days' => [['options' => ['summary_days' => [1, 1]]]],
            'invalid day' => [['options' => ['summary_days' => [7]]]],
            'incomplete schedule' => [['options' => ['summary_days' => [1]]]],
        ];
    }
}
