<?php

namespace Tests\Feature\Api;

use App\Models\CreatorProfile;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_new_account_has_no_profile_and_real_zero_counts(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mobile']);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.onboarded', false)
            ->assertJsonPath('data.interests', [])
            ->assertJsonPath('data.capabilities.creator_access', false)
            ->assertJsonPath('data.stats', ['following' => 0, 'followers' => 0, 'podcasts' => 0, 'playlists' => 0])
            ->assertJsonPath('data.unread_notifications', 0)
            ->assertJsonMissingPath('data.password');
    }

    public function test_profile_counts_and_details_are_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => $user->name]);
        $show = Show::create(['rss_url' => 'https://example.invalid/profile.xml', 'title' => 'Profile test show']);
        $user->followedShows()->attach($show->id);
        DB::table('creator_followers')->insert(['creator_profile_id' => $creator->id, 'user_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$user, $other] as $owner) {
            DB::table('playlists')->insert(['id' => (string) Str::ulid(), 'user_id' => $owner->id, 'name' => 'Owned playlist', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_profiles')->insert(['user_id' => $owner->id, 'bio' => 'Bio '.$owner->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('notifications')->insert(['id' => (string) Str::ulid(), 'user_id' => $owner->id, 'type' => 'account', 'deduplication_key' => 'profile-test', 'title' => 'Account update', 'body' => 'Updated', 'created_at' => now(), 'updated_at' => now()]);
        }
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user, ['mobile']);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.profile.bio', 'Bio '.$user->id)
            ->assertJsonPath('data.capabilities.creator_access', true)
            ->assertJsonPath('data.stats', ['following' => 1, 'followers' => 1, 'podcasts' => 1, 'playlists' => 1])
            ->assertJsonPath('data.unread_notifications', 1);
    }

    public function test_profile_update_cannot_change_another_user_or_grant_creator_access(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['name' => 'Other account']);
        Sanctum::actingAs($user, ['mobile']);

        $this->patchJson('/api/v1/me', ['user_id' => $other->id, 'name' => 'Updated name', 'bio' => 'Saved bio', 'status' => 'admin', 'capabilities' => ['creator_access' => true]])->assertOk()
            ->assertJsonPath('data.user.name', 'Updated name')
            ->assertJsonPath('data.profile.bio', 'Saved bio');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated name', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => 'Other account']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'bio' => 'Saved bio']);
        $this->assertDatabaseCount('verified_show_claims', 0);
    }

    public function test_duplicate_handle_does_not_partially_save_profile_changes(): void
    {
        User::factory()->create(['handle' => 'taken_handle']);
        $user = User::factory()->create(['name' => 'Original']);
        Sanctum::actingAs($user, ['mobile']);

        $this->patchJson('/api/v1/me', ['name' => 'Not saved', 'handle' => 'taken_handle'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Original']);
    }

    public function test_photo_upload_requires_authentication(): void
    {
        Storage::fake('public');

        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.png', 50, 50)])
            ->assertUnauthorized();

        $this->assertSame([], Storage::disk('public')->allFiles('avatars'));
    }

    public function test_photo_upload_reencodes_and_persists_only_for_the_current_account(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);

        $response = $this->postJson('/api/v1/me/avatar', ['user_id' => $other->id, 'avatar' => UploadedFile::fake()->image('photo.png', 100, 100)])->assertOk();
        $url = $response->json('data.avatar_url');

        $this->assertStringStartsWith('/storage/avatars/'.$user->id.'/', $url);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'avatar_url' => $url]);
        $this->assertDatabaseMissing('user_profiles', ['user_id' => $other->id]);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        $this->assertSame('image/jpeg', Storage::disk('public')->mimeType(substr($url, strlen('/storage/'))));
    }

    public function test_active_images_are_rejected_without_changing_the_profile(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);

        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION');

        $this->assertDatabaseMissing('user_profiles', ['user_id' => $user->id]);
        $this->assertSame([], Storage::disk('public')->allFiles('avatars'));
    }

    public function test_photo_dimensions_are_bounded(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(), ['mobile']);

        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('too-wide.png', 2050, 10)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION');

        $this->assertSame([], Storage::disk('public')->allFiles('avatars'));
    }

    public function test_replacing_a_photo_removes_only_the_previous_owned_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $oldPath = 'avatars/'.$user->id.'/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($oldPath, 'old image');
        Storage::disk('public')->put('avatars/other-user/photo.jpg', 'other image');
        DB::table('user_profiles')->insert(['user_id' => $user->id, 'avatar_url' => '/storage/'.$oldPath, 'bio' => 'Keep my bio', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user, ['mobile']);

        $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('replacement.jpg', 100, 100)])->assertOk();

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists('avatars/other-user/photo.jpg');
        Storage::disk('public')->assertExists(substr($response->json('data.avatar_url'), strlen('/storage/')));
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'bio' => 'Keep my bio']);
    }

    public function test_photo_file_size_is_bounded(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(), ['mobile']);

        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('large.jpg', 100, 100)->size(5121)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION');

        $this->assertSame([], Storage::disk('public')->allFiles('avatars'));
    }

    public function test_authenticated_password_change_keeps_the_current_session(): void
    {
        $user = User::factory()->create(['password' => 'Correct-Horse-9!']);
        Sanctum::actingAs($user, ['mobile']);

        $this->patchJson('/api/v1/me/password', [
            'current_password' => 'Correct-Horse-9!',
            'password' => 'New-Horse-Battery-9!',
            'password_confirmation' => 'New-Horse-Battery-9!',
        ])->assertOk()->assertJsonPath('data.password_changed', true);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}
