<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MobileOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_requires_authentication(): void
    {
        $this->putJson('/api/v1/me/onboarding', ['interests' => ['Technology'], 'locale' => 'en'])->assertUnauthorized();
        $this->assertDatabaseCount('user_interests', 0);
    }

    public function test_saves_catalog_interests_and_locale_only_for_the_authenticated_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/me/onboarding', [
            'user_id' => $other->id,
            'interests' => ['Technology', 'True Crime', 'Culture'],
            'locale' => 'yo',
        ])->assertOk()
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.interests', ['technology', 'true crime', 'culture'])
            ->assertJsonPath('data.locale', 'yo');

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertNotNull($owner->fresh()->onboarded_at);
        $this->assertNull($other->fresh()->onboarded_at);
        $this->assertDatabaseHas('user_interests', ['user_id' => $owner->id, 'interest' => 'technology']);
        $this->assertDatabaseHas('user_interests', ['user_id' => $owner->id, 'interest' => 'true crime']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $owner->id, 'locale' => 'yo']);
        $this->assertDatabaseMissing('user_interests', ['user_id' => $other->id]);
        $this->assertDatabaseMissing('user_profiles', ['user_id' => $other->id]);

        $this->actingAs($other, 'sanctum')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.onboarded', false)
            ->assertJsonPath('data.interests', []);
    }

    public function test_omitted_locale_does_not_invent_a_profile_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/onboarding', ['interests' => ['Music']])->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.locale', null)
            ->assertJsonPath('data.interests', ['music']);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.interests', ['music']);
    }

    public function test_repeat_submission_replaces_interests_and_preserves_unrelated_profile_fields(): void
    {
        $user = User::factory()->create();
        DB::table('user_profiles')->insert([
            'user_id' => $user->id,
            'bio' => 'Kept biography',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/onboarding', ['interests' => ['News', 'Sports'], 'locale' => 'fr'])->assertOk();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/onboarding', ['interests' => ['Health'], 'locale' => 'ha'])->assertOk()
            ->assertJsonPath('data.interests', ['health'])
            ->assertJsonPath('data.locale', 'ha');

        $this->assertDatabaseHas('user_interests', ['user_id' => $user->id, 'interest' => 'health']);
        $this->assertDatabaseMissing('user_interests', ['user_id' => $user->id, 'interest' => 'news']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'bio' => 'Kept biography', 'locale' => 'ha']);
        $this->assertDatabaseCount('user_interests', 1);
    }

    #[DataProvider('invalidOnboarding')]
    public function test_invalid_onboarding_is_not_persisted(array $payload, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->putJson('/api/v1/me/onboarding', $payload)->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION')
            ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        $this->assertDatabaseCount('user_interests', 0);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseHas('users', ['onboarded_at' => null]);
    }

    public static function invalidOnboarding(): array
    {
        return [
            'missing interests' => [['locale' => 'en'], 'interests'],
            'empty interests' => [['interests' => []], 'interests'],
            'unknown interest' => [['interests' => ['Invented']], 'interests'],
            'duplicate interest' => [['interests' => ['News', 'news']], 'interests.1'],
            'invalid locale' => [['interests' => ['News'], 'locale' => 'xx'], 'locale'],
        ];
    }
}
