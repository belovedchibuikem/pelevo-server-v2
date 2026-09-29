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

    public function test_admin_login_still_lives_under_admin(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->withoutVite();
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Login'));
    }
}
