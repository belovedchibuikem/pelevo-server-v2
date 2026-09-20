<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessAiJob;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PhaseSixAdvancedGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_device_pairing_listing_and_owner_scoped_revocation(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Device::create(['user_id' => $other->id, 'device_identifier' => 'foreign', 'name' => 'Foreign']);
        $code = $this->actingAs($user, 'sanctum')->postJson('/api/v1/devices/pairing-code')->assertCreated()->json('data.code');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/devices/pair', ['code' => $code, 'device_identifier' => 'new-phone', 'name' => 'New phone', 'platform' => 'android'])->assertCreated();
        $device = DB::table('devices')->where('user_id', $user->id)->where('device_identifier', 'new-phone')->first();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/devices')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/devices/'.$foreign->id)->assertNotFound();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/devices/'.$device->id)->assertOk();
        $this->assertDatabaseHas('devices', ['id' => $device->id, 'trust_state' => 'revoked']);
    }

    public function test_sync_detects_version_conflict_and_owner_can_resolve_it(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'sync-phone', 'name' => 'Phone']);
        $resource = (string) Str::ulid();
        $payload = fn (int $version, string $name) => ['changes' => [['resource_type' => 'playlist', 'resource_id' => $resource, 'operation' => 'update', 'version' => $version, 'payload' => ['name' => $name]]]];
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier);
        $client->postJson('/api/v1/sync/push', $payload(1, 'Original'))->assertOk()->assertJsonPath('data.accepted', 1);
        $conflict = $client->postJson('/api/v1/sync/push', $payload(3, 'Conflicting'))->assertStatus(207)->json('data.conflicts.0');
        $open = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sync/conflicts')->assertOk();
        $this->assertSame(['id', 'created_at', 'state', 'resource_type'], array_keys($open->json('data.0')));
        $this->assertArrayNotHasKey('client_payload', $open->json('data.0'));
        $pull = $client->getJson('/api/v1/sync/pull')->assertOk();
        $this->assertSame(['id', 'resource_type', 'resource_id', 'operation', 'version'], array_keys($pull->json('data.0')));
        $this->assertArrayNotHasKey('payload', $pull->json('data.0'));
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/sync/conflicts/'.$conflict, ['resolution' => 'client', 'payload' => ['name' => 'Resolved']])->assertOk();
        $this->assertDatabaseHas('sync_conflicts', ['id' => $conflict, 'state' => 'resolved', 'resolution' => 'client']);
    }

    public function test_encrypted_backup_restore_share_cards_and_notification_privacy_are_scoped(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'backup-phone', 'name' => 'Phone']);
        DB::table('sync_resource_states')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'resource_type' => 'note', 'resource_id' => (string) Str::ulid(), 'version' => 1, 'payload' => json_encode(['body' => 'private']), 'device_id' => $device->id, 'created_at' => now(), 'updated_at' => now()]);
        $backup = $this->actingAs($user, 'sanctum')->postJson('/api/v1/backups')->assertCreated()->json('data.id');
        $cipher = DB::table('user_backups')->where('id', $backup)->value('payload_encrypted');
        $this->assertStringNotContainsString('private', $cipher);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/backups/'.$backup.'/restore')->assertOk();
        $template = DB::table('share_card_templates')->value('id');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/share-card-templates')->assertOk();
        $this->assertSame(['id', 'name', 'version'], array_keys($this->actingAs($user, 'sanctum')->getJson('/api/v1/share-card-templates')->json('data.0')));
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/share-cards', ['template_id' => $template, 'subject_type' => 'playlist', 'subject_id' => (string) Str::ulid(), 'payload' => ['quote' => 'Listen']])->assertStatus(422);
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/share-cards', ['template_id' => $template, 'subject_type' => 'episode', 'subject_id' => (string) Str::ulid(), 'payload' => ['quote' => 'Listen', 'variant' => 'quote']])->assertCreated();
        $card = $created->json('data.id');
        $this->assertArrayHasKey('url', $created->json('data'));
        $this->assertSame('Listen', $created->json('data.quote'));
        $this->assertArrayNotHasKey('public_token', $created->json('data'));
        $this->assertArrayNotHasKey('user_id', $created->json('data'));
        $this->assertArrayNotHasKey('payload', $created->json('data'));
        $list = $this->actingAs($user, 'sanctum')->getJson('/api/v1/share-cards')->assertOk();
        $this->assertArrayNotHasKey('user_id', $list->json('data.0'));
        $this->assertArrayNotHasKey('public_token', $list->json('data.0'));
        $detail = $this->actingAs($user, 'sanctum')->getJson('/api/v1/share-cards/'.$card)->assertOk();
        $this->assertIsArray($detail->json('data.events'));
        $this->assertArrayNotHasKey('card', $detail->json('data'));
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/share-cards/'.$card)->assertNotFound();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notification-lock-rules', ['show_title' => true, 'show_body' => false, 'sensitive_hidden' => true])->assertOk()->assertJsonPath('data.sensitive_hidden', true)->assertJsonMissingPath('data.user_id');
    }

    public function test_ai_requires_feature_prompt_and_dispatches_bounded_job(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/ai/jobs', ['type' => 'summary', 'input' => ['text' => 'Hello']])->assertForbidden();
        config()->set('features.ai', true);
        DB::table('prompt_versions')->insert(['id' => (string) Str::ulid(), 'type' => 'summary', 'version' => 1, 'prompt' => 'Summarize safely.', 'rollout_percent' => 100, 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        Queue::fake([ProcessAiJob::class]);
        $job = $this->actingAs($user, 'sanctum')->postJson('/api/v1/ai/jobs', ['type' => 'summary', 'input' => ['text' => 'Hello']])->assertAccepted()->json('data.id');
        Queue::assertPushed(fn (ProcessAiJob $queued) => $queued->jobId === $job);
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/ai/jobs/'.$job)->assertNotFound();
    }

    public function test_cms_support_and_feedback_are_real_persisted_surfaces(): void
    {
        $user = User::factory()->create();
        DB::table('cms_pages')->where('slug', 'help')->update(['state' => 'published', 'published_at' => now()->subMinute()]);
        $this->getJson('/api/v1/cms/help')->assertOk()->assertJsonPath('data.slug', 'help');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/tickets', ['subject' => 'Playback issue', 'message' => 'Audio stops after a few seconds.', 'priority' => 'high'])->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/feedback', ['type' => 'idea', 'message' => 'Add a sleep timer.'])->assertCreated();
        $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id, 'priority' => 'high']);
        $this->assertDatabaseHas('feedback', ['user_id' => $user->id, 'type' => 'idea']);
    }

    public function test_push_token_registers_against_device_id_or_identifier(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'phone-identifier', 'name' => 'Phone']);
        $payload = ['provider' => 'fcm', 'token' => str_repeat('a', 32)];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/push-tokens', $payload)->assertForbidden();
        $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->id)->postJson('/api/v1/me/push-tokens', $payload)
            ->assertCreated()
            ->assertJsonPath('data.registered', true)
            ->assertJsonPath('data.device_id', $device->id);
        $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', 'phone-identifier')->postJson('/api/v1/me/push-tokens', $payload)->assertCreated();
        $this->assertDatabaseCount('push_tokens', 1);
    }
}
