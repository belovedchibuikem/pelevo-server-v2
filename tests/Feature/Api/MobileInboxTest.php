<?php

namespace Tests\Feature\Api;

use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.notifications', true);
    }

    private function notification(User $user, array $values = []): string
    {
        $id = (string) Str::ulid();
        DB::table('notifications')->insert(['id' => $id, 'user_id' => $user->id, 'type' => 'mention', 'title' => 'Account event', 'body' => 'Stored message', 'deduplication_key' => $id, 'created_at' => now(), 'updated_at' => now(), ...$values]);

        return $id;
    }

    public function test_private_lists_and_mutations_require_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/me/following')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/clear', ['confirmed' => true])->assertUnauthorized();
        $this->postJson('/api/v1/notifications/bulk', ['action' => 'read', 'ids' => [(string) Str::ulid()]])->assertUnauthorized();
    }

    public function test_inbox_filters_and_cursor_pages_exclude_other_accounts(): void
    {
        $owner = User::factory()->create();
        $first = $this->notification($owner, ['created_at' => now()->subMinute()]);
        $second = $this->notification($owner, ['created_at' => now()->subMinutes(2), 'type' => 'broadcast', 'read_at' => now()]);
        $this->notification(User::factory()->create());
        $this->actingAs($owner, 'sanctum');

        $page = $this->getJson('/api/v1/notifications?limit=1')->assertOk()->assertJsonPath('data.0.id', $first)->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.unread_count', 1)->assertJsonMissingPath('data.0.deduplication_key');
        $this->getJson('/api/v1/notifications?limit=1&cursor='.urlencode($page->json('meta.cursor')))->assertOk()->assertJsonPath('data.0.id', $second)->assertJsonPath('meta.has_more', false);
        $this->getJson('/api/v1/notifications?view=unread')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
        $this->getJson('/api/v1/notifications?view=mentions')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
    }

    public function test_read_is_owner_scoped_and_retries_preserve_the_first_read_time(): void
    {
        $owner = User::factory()->create();
        $firstRead = now()->subHour()->startOfSecond();
        $id = $this->notification($owner, ['read_at' => $firstRead]);
        $foreign = $this->notification(User::factory()->create());
        $this->actingAs($owner, 'sanctum');

        $this->putJson('/api/v1/notifications/'.$id.'/read')->assertOk()->assertJsonPath('data.read', true);
        $this->putJson('/api/v1/notifications/'.$foreign.'/read')->assertNotFound();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => $firstRead]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign, 'read_at' => null]);
    }

    public function test_bulk_read_and_dismiss_are_confirmed_and_history_retains_records(): void
    {
        $owner = User::factory()->create();
        $id = $this->notification($owner);
        $this->actingAs($owner, 'sanctum');

        $this->postJson('/api/v1/notifications/bulk', ['action' => 'read', 'ids' => [$id]])->assertOk()->assertJsonPath('data.ids', [$id]);
        $this->assertDatabaseMissing('notifications', ['id' => $id, 'read_at' => null]);
        $this->postJson('/api/v1/notifications/bulk', ['action' => 'dismiss', 'ids' => [$id]])->assertOk()->assertJsonPath('data.action', 'dismiss');
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
        $this->getJson('/api/v1/notifications?view=history')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_bulk_foreign_ids_fail_atomically(): void
    {
        $owner = User::factory()->create();
        $id = $this->notification($owner);
        $foreign = $this->notification(User::factory()->create());

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/notifications/bulk', ['action' => 'read', 'ids' => [$id, $foreign]])->assertNotFound();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign, 'read_at' => null]);
    }

    public function test_clear_requires_confirmation_and_never_clears_another_account(): void
    {
        $owner = User::factory()->create();
        $id = $this->notification($owner);
        $foreign = $this->notification(User::factory()->create());
        $this->actingAs($owner, 'sanctum');

        $this->postJson('/api/v1/notifications/clear')->assertUnprocessable();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'dismissed_at' => null]);
        $this->postJson('/api/v1/notifications/clear', ['confirmed' => true])->assertOk()->assertJsonPath('data.count', 1);
        $this->assertDatabaseMissing('notifications', ['id' => $id, 'dismissed_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign, 'dismissed_at' => null]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.unread_notifications', 0);
    }

    public function test_invalid_pages_and_bulk_payloads_do_not_mutate_records(): void
    {
        $owner = User::factory()->create();
        $id = $this->notification($owner);
        $this->actingAs($owner, 'sanctum');

        $this->getJson('/api/v1/notifications?limit=0')->assertUnprocessable();
        $this->getJson('/api/v1/notifications?view=invalid')->assertUnprocessable();
        $this->postJson('/api/v1/notifications/bulk', ['action' => 'read', 'ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/bulk', ['action' => 'read', 'ids' => [$id, $id]])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/bulk', ['action' => 'invalid', 'ids' => [$id]])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/bulk', ['action' => 'read', 'ids' => ['invalid']])->assertUnprocessable();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null, 'dismissed_at' => null]);
    }

    public function test_disabled_feature_blocks_inbox_mutations(): void
    {
        $owner = User::factory()->create();
        $id = $this->notification($owner);
        config()->set('features.notifications', false);
        $this->actingAs($owner, 'sanctum');

        $this->getJson('/api/v1/notifications')->assertForbidden();
        $this->putJson('/api/v1/notifications/'.$id.'/read')->assertForbidden();
        $this->postJson('/api/v1/notifications/bulk', ['action' => 'read', 'ids' => [$id]])->assertForbidden();
        $this->postJson('/api/v1/notifications/clear', ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null, 'dismissed_at' => null]);
    }

    public function test_following_is_paged_and_unfollow_preserves_other_followers(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $show = Show::create(['title' => 'A followed show', 'author' => 'Its author', 'rss_url' => 'https://feed.example.invalid/a', 'status' => 'active']);
        $second = Show::create(['title' => 'Another followed show', 'rss_url' => 'https://feed.example.invalid/b', 'status' => 'active']);
        $owner->followedShows()->attach([$show->id, $second->id]);
        $other->followedShows()->attach($show->id);
        $this->actingAs($owner, 'sanctum');

        $page = $this->getJson('/api/v1/me/following?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', true);
        $next = $this->getJson('/api/v1/me/following?limit=1&cursor='.urlencode($page->json('meta.cursor')))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
        $this->assertNotSame($page->json('data.0.id'), $next->json('data.0.id'));
        $this->deleteJson('/api/v1/shows/'.$show->id.'/follow')->assertOk()->assertJsonPath('data.following', false);
        $this->assertDatabaseMissing('follows', ['user_id' => $owner->id, 'show_id' => $show->id]);
        $this->assertDatabaseHas('follows', ['user_id' => $other->id, 'show_id' => $show->id]);
    }
}
