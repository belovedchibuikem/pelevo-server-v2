<?php

namespace Tests\Feature\Mail;

use App\Events\NewEpisodePublished;
use App\Jobs\DispatchNotificationBroadcast;
use App\Jobs\PrepareDataExport;
use App\Jobs\SendNotificationDigests;
use App\Listeners\CreateNewEpisodeNotifications;
use App\Mail\DigestMail;
use App\Mail\NewEpisodeAlert;
use App\Mail\PelevoNotice;
use App\Models\Admin;
use App\Models\CreatorProfile;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use App\Services\MailPreference;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AlertMailGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_email_toggle_gates_alerts_and_leaves_transactional_mail_on(): void
    {
        Mail::fake();
        $on = User::factory()->create();
        $off = User::factory()->create();
        $this->preferences($off, ['email_enabled' => false]);
        $show = Show::create(['rss_url' => 'https://example.com/gate.xml', 'title' => 'Gate Show', 'author' => 'Host']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'gate-ep', 'title' => 'Gate episode', 'audio_url' => 'https://example.com/gate.mp3']);
        $on->followedShows()->attach($show);
        $off->followedShows()->attach($show);

        (new CreateNewEpisodeNotifications)->handle(new NewEpisodePublished($episode));

        Mail::assertQueued(NewEpisodeAlert::class, fn (NewEpisodeAlert $mail): bool => $mail->hasTo($on->email));
        Mail::assertNotQueued(NewEpisodeAlert::class, fn (NewEpisodeAlert $mail): bool => $mail->hasTo($off->email));
        $this->assertTrue(app(MailPreference::class)->alertsEnabled($on->id));
        $this->assertFalse(app(MailPreference::class)->alertsEnabled($off->id));
    }

    public function test_live_and_broadcast_and_digest_respect_email_enabled(): void
    {
        Mail::fake();
        $on = User::factory()->create();
        $off = User::factory()->create();
        $this->preferences($on, [
            'email_enabled' => true,
            'summary_time' => '08:00',
            'summary_days' => [2],
            'summary_include' => ['new_episodes', 'promotions'],
        ], 'UTC');
        $this->preferences($off, [
            'email_enabled' => false,
            'summary_time' => '08:00',
            'summary_days' => [2],
            'summary_include' => ['new_episodes', 'promotions'],
        ], 'UTC');

        $host = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $host->id, 'display_name' => 'Host']);
        $show = Show::create(['rss_url' => 'https://example.com/live-mail.xml', 'title' => 'Live Mail']);
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        $on->followedShows()->attach($show);
        $off->followedShows()->attach($show);
        $session = $this->actingAs($host, 'sanctum')->postJson('/api/v1/live-sessions', ['title' => 'Morning Live', 'show_id' => $show->id])->assertCreated()->json('data.id');
        $this->actingAs($host, 'sanctum')->putJson("/api/v1/live-sessions/{$session}/state", ['state' => 'live'])->assertOk();
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($on->email) && $mail->heading === 'Morning Live is live');
        Mail::assertNotQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($off->email) && $mail->heading === 'Morning Live is live');

        $admin = Admin::create(['name' => 'Operator', 'email' => 'notify@example.test', 'password' => 'Test-password-9!', 'status' => 'active']);
        $template = (string) Str::ulid();
        $broadcast = (string) Str::ulid();
        DB::table('notification_templates')->insert(['id' => $template, 'key' => 'mail-gate', 'version' => 1, 'title' => 'Pelevo update', 'body' => 'A product update.', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_broadcasts')->insert(['id' => $broadcast, 'notification_template_id' => $template, 'audience' => '{}', 'state' => 'scheduled', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        (new DispatchNotificationBroadcast($broadcast))->handle();
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($on->email) && $mail->heading === 'Pelevo update');
        Mail::assertNotQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($off->email) && $mail->heading === 'Pelevo update');

        DB::table('notifications')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $on->id,
            'type' => 'new_episode',
            'deduplication_key' => 'digest-on',
            'title' => 'Digest episode',
            'body' => 'A followed show published.',
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $off->id,
            'type' => 'new_episode',
            'deduplication_key' => 'digest-off',
            'title' => 'Digest episode',
            'body' => 'A followed show published.',
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->travelTo(Carbon::parse('2026-09-22 08:02:00', 'UTC'));
        (new SendNotificationDigests)->handle(app(MailPreference::class));
        Mail::assertQueued(DigestMail::class, fn (DigestMail $mail): bool => $mail->hasTo($on->email));
        Mail::assertNotQueued(DigestMail::class, fn (DigestMail $mail): bool => $mail->hasTo($off->email));
    }

    public function test_welcome_contact_and_export_are_transactional(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->withHeaders(['X-Device-Id' => 'mail-gate-device'])->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada-mail@example.com',
            'password' => 'Correct-Horse-9!',
            'password_confirmation' => 'Correct-Horse-9!',
            'device_name' => 'Phone',
        ])->assertCreated();
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo('ada-mail@example.com') && $mail->heading === 'Your Nigerian podcast home');

        $this->from('/contact')->post('/contact', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'audience' => 'creator',
            'subject' => 'Claim question',
            'message' => 'How long does RSS verification take?',
            'company_website' => '',
        ])->assertRedirect('/contact');
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo('ada@example.test') && $mail->heading === 'Thanks, we have your note');
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->heading === 'New website contact');

        $user = User::factory()->create(['password' => 'Correct-Horse-9!']);
        Sanctum::actingAs($user, ['mobile']);
        $export = $this->postJson('/api/v1/me/data-export')->assertAccepted()->json('data.id');
        (new PrepareDataExport($export))->handle();
        Mail::assertQueued(PelevoNotice::class, function (PelevoNotice $mail) use ($user): bool {
            return $mail->hasTo($user->email) && $mail->heading === 'Download your Pelevo data' && is_string($mail->actionUrl);
        });
        $download = collect(Mail::queued(PelevoNotice::class))->first(fn (PelevoNotice $mail): bool => $mail->heading === 'Download your Pelevo data');
        $this->get($download->actionUrl)->assertOk()->assertHeader('cache-control', 'no-store, private');
        $this->get('/data-exports/'.$export)->assertForbidden();
    }

    public function test_notice_and_digest_templates_are_branded(): void
    {
        $notice = (new PelevoNotice(
            subjectLine: 'Support reply',
            eyebrow: 'Support',
            heading: 'A reply on your support ticket',
            intro: 'The Pelevo team replied.',
            detail: 'Please resume playback.',
            actionLabel: 'Open Pelevo',
            actionUrl: 'https://pelevo.com',
        ))->render();
        $this->assertStringContainsString('PELEVO', $notice);
        $this->assertStringContainsString('Please resume playback.', $notice);
        $this->assertStringContainsString('https://pelevo.com', $notice);

        $digest = (new DigestMail([
            ['title' => 'Lagos after dark', 'body' => 'A new episode is available.'],
        ], 'Tuesday Sep 22'))->render();
        $this->assertStringContainsString('PELEVO', $digest);
        $this->assertStringContainsString('Lagos after dark', $digest);
        $this->assertStringContainsString('Catch up on Pelevo', $digest);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function preferences(User $user, array $options, string $timezone = 'UTC'): void
    {
        DB::table('notification_preferences')->updateOrInsert(['user_id' => $user->id], [
            'new_episodes' => true,
            'push_enabled' => true,
            'timezone' => $timezone,
            'mobile_options' => json_encode($options, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
