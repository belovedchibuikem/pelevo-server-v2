<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_is_the_public_landing_page(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->has('stores.ios')
            ->has('stores.android'));
    }

    public function test_marketing_pages_render(): void
    {
        $this->withoutVite();
        $this->get('/how-it-works')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/HowItWorks'));
        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/Legal')
            ->where('document.title', 'Privacy Policy')
            ->where('document.updated', '30th September 2026')
            ->where('document.kicker', 'Last updated: 30th September 2026')
            ->where('document.sections.0.heading', '1. Who We Are')
            ->has('document.sections', 14));
        $this->get('/terms')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.title', 'Terms and Conditions of Use')
            ->where('document.kicker', 'Last updated: 30th September 2026')
            ->has('document.sections', 18)
            ->where('document.sections.0.heading', '1. Eligibility')
            ->where('document.sections.17.heading', '18. Contact'));
        $this->get('/contact')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Contact'));
    }

    public function test_contact_form_persists_an_inquiry(): void
    {
        Mail::fake();
        $this->withoutVite();
        $this->from('/contact')->post('/contact', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'audience' => 'creator',
            'subject' => 'Claim question',
            'message' => 'How long does RSS description verification usually take?',
            'company_website' => '',
        ])->assertRedirect('/contact');

        $this->assertDatabaseHas('contact_inquiries', [
            'email' => 'ada@example.test',
            'audience' => 'creator',
            'state' => 'new',
        ]);
    }

    public function test_contact_honeypot_does_not_persist(): void
    {
        $this->withoutVite();
        $this->from('/contact')->post('/contact', [
            'name' => 'Bot',
            'email' => 'bot@example.test',
            'audience' => 'other',
            'subject' => 'Spam',
            'message' => 'Spam body',
            'company_website' => 'https://spam.test',
        ])->assertRedirect('/contact');

        $this->assertSame(0, DB::table('contact_inquiries')->count());
    }

    public function test_founding_creator_form_renders_with_frequency_options(): void
    {
        $this->withoutVite();
        $this->get('/founding-creators')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/FoundingCreators')
            ->has('frequencies', 5)
            ->where('frequencies.0.value', 'weekly'));
    }

    public function test_founding_creator_sign_up_is_stored_and_confirmed(): void
    {
        Mail::fake();
        $this->withoutVite();
        $payload = [
            'name' => 'Chioma Obi',
            'show_name' => 'Lagos After Dark',
            'show_url' => 'open.spotify.com/show/abc123',
            'email' => 'Chioma@Example.test',
            'social_handle' => '@lagosafterdark',
            'publish_frequency' => 'weekly',
            'notes' => 'We record in Pidgin.',
            'company_website' => '',
        ];

        $this->from('/founding-creators')->post('/founding-creators', $payload)
            ->assertRedirect('/founding-creators')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('founding_creator_applications', [
            'email' => 'chioma@example.test',
            'show_name' => 'Lagos After Dark',
            'show_url' => 'https://open.spotify.com/show/abc123',
            'publish_frequency' => 'weekly',
            'state' => 'new',
            'submission_count' => 1,
        ]);

        $this->from('/founding-creators')->post('/founding-creators', [...$payload, 'show_name' => 'lagos after dark', 'publish_frequency' => 'monthly']);
        $this->assertSame(1, DB::table('founding_creator_applications')->count());
        $this->assertDatabaseHas('founding_creator_applications', ['publish_frequency' => 'monthly', 'submission_count' => 2]);
    }

    public function test_founding_creator_sign_up_validates_required_fields(): void
    {
        $this->withoutVite();
        $this->from('/founding-creators')->post('/founding-creators', [
            'name' => '',
            'show_name' => '',
            'show_url' => 'not a link',
            'email' => 'nope',
            'publish_frequency' => 'daily',
        ])->assertSessionHasErrors(['name', 'show_name', 'show_url', 'email', 'publish_frequency']);

        $this->assertSame(0, DB::table('founding_creator_applications')->count());
    }

    public function test_founding_creator_honeypot_does_not_persist(): void
    {
        $this->withoutVite();
        $this->from('/founding-creators')->post('/founding-creators', [
            'name' => 'Bot',
            'show_name' => 'Spam show',
            'show_url' => 'https://spam.test',
            'email' => 'bot@example.test',
            'company_website' => 'https://spam.test',
        ])->assertRedirect('/founding-creators');

        $this->assertSame(0, DB::table('founding_creator_applications')->count());
    }

    public function test_admin_login_still_lives_under_admin(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->withoutVite();
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Login'));
    }
}
