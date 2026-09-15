<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupportWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ticket_detail_notes_assignment_and_conflicting_edits_preserve_history(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Support', 'email' => 'ticket@example.test', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'support')->value('id')]);
        $user = User::factory()->create();
        $ticket = (string) Str::ulid();
        DB::table('support_tickets')->insert(['id' => $ticket, 'user_id' => $user->id, 'subject' => 'Playback support', 'message' => 'The episode will not play.', 'state' => 'open', 'priority' => 'normal', 'version' => 1, 'sla_due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $this->get('/admin/support/tickets/'.$ticket)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/SupportTicket')->where('requester.id', $user->id)->has('audit')->has('notes'));
        $payload = ['version' => 1, 'state' => 'pending', 'priority' => 'high', 'assigned_admin_id' => $admin->id, 'note' => 'Investigating playback failure.', 'reason' => 'Assigned for playback investigation'];
        $this->putJson('/api/admin/v1/support/tickets/'.$ticket, $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/admin/v1/support/tickets/'.$ticket, $payload)->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket, 'version' => 2, 'state' => 'pending']);
        $this->assertSame(1, DB::table('support_ticket_notes')->where('support_ticket_id', $ticket)->count());
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $ticket, 'action' => 'support.updated']);

        $reply = [...$payload, 'version' => 2, 'note' => null, 'reply' => 'Please resume playback from your library.'];
        $this->putJson('/api/admin/v1/support/tickets/'.$ticket, $reply)->assertUnprocessable();
        $this->putJson('/api/admin/v1/support/tickets/'.$ticket, [...$reply, 'confirm_reply' => true])->assertOk();
        $this->putJson('/api/admin/v1/support/tickets/'.$ticket, [...$reply, 'confirm_reply' => true])->assertConflict();
        $this->assertSame(1, DB::table('notifications')->where('user_id', $user->id)->where('type', 'support_reply')->count());
        $this->assertDatabaseHas('support_ticket_notes', ['support_ticket_id' => $ticket, 'public_reply' => true, 'body' => $reply['reply']]);

        DB::table('notification_preferences')->insert(['user_id' => $user->id, 'new_episodes' => true, 'push_enabled' => false, 'timezone' => 'UTC', 'mobile_options' => json_encode(['in_app_enabled' => false]), 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson('/api/admin/v1/support/tickets/'.$ticket, [...$reply, 'version' => 3, 'reply' => 'Your issue remains under investigation.', 'confirm_reply' => true])->assertOk();
        $this->assertDatabaseHas('support_ticket_notes', ['support_ticket_id' => $ticket, 'public_reply' => true, 'body' => 'Your issue remains under investigation.']);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $user->id)->where('type', 'support_reply')->count());

        Storage::fake('local');
        $attachment = $this->post('/api/admin/v1/support/tickets/'.$ticket.'/attachments', ['file' => UploadedFile::fake()->createWithContent('playback.txt', 'Playback stopped at 02:30.'), 'reason' => 'Attach playback diagnostic evidence'], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->get('/api/admin/v1/support/tickets/'.$ticket.'/attachments/'.$attachment)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/admin/v1/support/tickets/'.Str::ulid().'/attachments/'.$attachment)->assertNotFound();
        $this->post('/api/admin/v1/support/tickets/'.$ticket.'/attachments', ['file' => UploadedFile::fake()->createWithContent('unsafe.html', '<script>alert(1)</script>'), 'reason' => 'Validate unsafe attachment rejection'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $ticket, 'action' => 'support.attachment_downloaded']);
        $related = (string) Str::ulid();
        DB::table('support_tickets')->insert(['id' => $related, 'user_id' => $user->id, 'subject' => 'Related playback incident', 'message' => 'Matching symptoms', 'sla_due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $link = ['kind' => 'ticket', 'target_id' => $related, 'reason' => 'Matching playback incident symptoms'];
        $this->postJson('/api/admin/v1/support/tickets/'.$ticket.'/links', $link)->assertCreated();
        $this->postJson('/api/admin/v1/support/tickets/'.$ticket.'/links', $link)->assertOk();
        $this->assertSame(1, DB::table('support_ticket_links')->where('support_ticket_id', $ticket)->count());
        $this->postJson('/api/admin/v1/support/tickets/'.$ticket.'/links', [...$link, 'kind' => 'reconciliation'])->assertUnprocessable();
        $this->get('/admin/support/tickets/'.$ticket)->assertInertia(fn (Assert $page) => $page->where('links.0.target_id', $related)->where('links.0.href', '/admin/support/tickets/'.$related));

        DB::table('admin_role')->where('admin_id', $admin->id)->delete();
        $this->get('/admin/support/tickets/'.$ticket)->assertForbidden();
        $this->get('/api/admin/v1/support/tickets/'.$ticket.'/attachments/'.$attachment)->assertForbidden();
        $this->putJson('/api/admin/v1/support/tickets/'.$ticket, [...$payload, 'version' => 2])->assertForbidden();
    }

    public function test_website_contact_inquiries_are_listed_and_triaged(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Support', 'email' => 'contact-ops@example.test', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'support')->value('id')]);
        $user = User::factory()->create(['email' => 'ada@example.test', 'name' => 'Ada Lovelace']);
        $inquiry = (string) Str::ulid();
        DB::table('contact_inquiries')->insert([
            'id' => $inquiry,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'audience' => 'creator',
            'subject' => 'Claim question',
            'message' => 'How long does RSS verification take?',
            'state' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $this->get('/admin/support')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ModuleWorkspace')
            ->where('activeView', 'contact')
            ->where('records.total', 1)
            ->where('records.data.0.email', 'ada@example.test')
            ->where('records.data.0.subject', 'Claim question'));
        $this->get('/admin/support/contact/'.$inquiry)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ContactInquiry')
            ->where('inquiry.email', 'ada@example.test')
            ->where('matchedUser.id', $user->id));
        $this->putJson('/api/admin/v1/support/contact/'.$inquiry, ['state' => 'resolved', 'reason' => 'Replied by email to the creator.'])
            ->assertOk()
            ->assertJsonPath('data.audit_reference', fn ($value): bool => is_string($value) && $value !== '');
        $this->assertDatabaseHas('contact_inquiries', ['id' => $inquiry, 'state' => 'resolved']);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $inquiry, 'action' => 'support.contact_updated']);

        $this->getJson('/api/admin/v1/search?q=Claim%20question')->assertOk()->assertJsonFragment([
            'label' => 'Website contact',
            'href' => '/admin/support/contact/'.$inquiry,
        ]);

        DB::table('admin_role')->where('admin_id', $admin->id)->delete();
        $this->get('/admin/support/contact/'.$inquiry)->assertForbidden();
        $this->putJson('/api/admin/v1/support/contact/'.$inquiry, ['state' => 'closed', 'reason' => 'Should be forbidden now.'])->assertForbidden();
    }
}
