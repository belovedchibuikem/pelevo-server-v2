<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_cms_normalizes_sections_without_exposing_admin_metadata(): void
    {
        DB::table('cms_pages')->where('slug', 'help')->update(['body' => "Published introduction.\n\n## Question from CMS\nAnswer from CMS.", 'version' => 2]);

        $this->getJson('/api/v1/cms/help')->assertOk()
            ->assertJsonPath('data.intro', 'Published introduction.')
            ->assertJsonPath('data.sections.0.title', 'Question from CMS')
            ->assertJsonPath('data.sections.0.body', 'Answer from CMS.')
            ->assertJsonPath('data.body_format', 'plain_text')
            ->assertJsonPath('data.version', 2)
            ->assertJsonMissingPath('data.updated_by');
    }

    public function test_published_legal_content_is_public_without_invented_fallbacks(): void
    {
        DB::table('cms_pages')->insert(['id' => (string) Str::ulid(), 'slug' => 'terms', 'title' => 'Published terms', 'body' => 'The approved CMS text.', 'version' => 1, 'state' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/v1/cms/terms')->assertOk()->assertJsonPath('data.intro', 'The approved CMS text.');
    }

    public function test_published_guidelines_are_public_without_client_tip_fallbacks(): void
    {
        DB::table('cms_pages')->insert([
            'id' => (string) Str::ulid(),
            'slug' => 'guidelines',
            'title' => 'Share card guidelines',
            'body' => "Use high contrast.\n\n## Keep text short\nKeep captions brief.",
            'version' => 1,
            'state' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/cms/guidelines')->assertOk()
            ->assertJsonPath('data.slug', 'guidelines')
            ->assertJsonPath('data.intro', 'Use high contrast.')
            ->assertJsonPath('data.sections.0.title', 'Keep text short')
            ->assertJsonPath('data.sections.0.body', 'Keep captions brief.');
    }

    public function test_unpublished_guidelines_return_not_found(): void
    {
        $this->getJson('/api/v1/cms/guidelines')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_draft_and_future_publications_are_not_exposed(): void
    {
        DB::table('cms_pages')->where('slug', 'help')->update(['state' => 'draft']);
        DB::table('cms_pages')->where('slug', 'about')->update(['published_at' => now()->addDay()]);

        $this->getJson('/api/v1/cms/help')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/cms/about')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/cms/privacy')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_unlisted_cms_slugs_are_not_accessible(): void
    {
        DB::table('cms_pages')->insert(['id' => (string) Str::ulid(), 'slug' => 'internal', 'title' => 'Internal', 'body' => 'Not a mobile public document.', 'version' => 1, 'state' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/v1/cms/internal')->assertNotFound();
    }

    public function test_submissions_require_authentication(): void
    {
        $this->postJson('/api/v1/support/tickets', ['subject' => 'Help', 'message' => 'Help with playback.'])->assertUnauthorized();
        $this->postJson('/api/v1/support/feedback', ['type' => 'idea', 'message' => 'Feedback.'])->assertUnauthorized();

        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('feedback', 0);
    }

    public function test_ticket_retry_returns_the_original_receipt_even_after_admin_processing(): void
    {
        $user = User::factory()->create();
        $data = ['subject' => 'Playback stopped', 'message' => 'The episode stopped playing.', 'client_request_id' => (string) Str::uuid()];
        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/tickets', $data)->assertCreated();
        DB::table('support_tickets')->where('id', $first->json('data.id'))->update(['state' => 'resolved', 'priority' => 'high']);

        $this->postJson('/api/v1/support/tickets', $data)->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.state', 'resolved');

        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id, 'subject' => 'Playback stopped', 'message' => 'The episode stopped playing.']);
    }

    public function test_a_retry_identifier_cannot_be_reused_with_different_content(): void
    {
        $user = User::factory()->create();
        $data = ['subject' => 'Help', 'message' => 'Original message.', 'client_request_id' => (string) Str::uuid()];
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/tickets', $data)->assertCreated();

        $this->postJson('/api/v1/support/tickets', [...$data, 'message' => 'Changed message.'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id, 'message' => 'Original message.']);
    }

    public function test_submission_identifiers_and_ownership_are_scoped_by_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $data = ['type' => 'idea', 'message' => 'Suggestion.', 'client_request_id' => (string) Str::uuid(), 'user_id' => $other->id];
        $first = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/support/feedback', $data)->assertCreated();

        $second = $this->actingAs($other, 'sanctum')->postJson('/api/v1/support/feedback', $data)->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseHas('feedback', ['id' => $first->json('data.id'), 'user_id' => $owner->id]);
        $this->assertDatabaseHas('feedback', ['id' => $second->json('data.id'), 'user_id' => $other->id]);
    }

    public function test_rating_feedback_is_validated_and_retry_safe(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/feedback', ['type' => 'rating', 'message' => 'My rating.'])
            ->assertUnprocessable()->assertJsonPath('error.fields.rating.0', 'The rating field is required when type is rating.');
        $data = ['type' => 'rating', 'rating' => 4, 'message' => 'My rating.', 'client_request_id' => (string) Str::uuid()];
        $first = $this->postJson('/api/v1/support/feedback', $data)->assertCreated();

        $this->postJson('/api/v1/support/feedback', $data)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('feedback', 1);
        $this->assertDatabaseHas('feedback', ['user_id' => $user->id, 'rating' => 4, 'type' => 'rating']);
    }

    public function test_invalid_ticket_payloads_do_not_create_records(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/v1/support/tickets', ['subject' => '', 'message' => ''])
            ->assertUnprocessable()->assertJsonPath('error.fields.subject.0', 'The subject field is required.');
        $this->postJson('/api/v1/support/tickets', ['subject' => str_repeat('a', 192), 'message' => 'Text'])
            ->assertUnprocessable()->assertJsonPath('error.fields.subject.0', 'The subject field must not be greater than 191 characters.');
        $this->postJson('/api/v1/support/tickets', ['subject' => 'Help', 'message' => 'Text', 'client_request_id' => 'not-a-uuid'])
            ->assertUnprocessable()->assertJsonPath('error.fields.client_request_id.0', 'The client request id field must be a valid UUID.');

        $this->assertDatabaseCount('support_tickets', 0);
    }
}
