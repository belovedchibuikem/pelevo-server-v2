<?php

namespace Tests\Feature\Mail;

use App\Mail\AdminPasswordReset;
use App\Mail\ClaimVerificationCode;
use App\Mail\NewEpisodeAlert;
use App\Mail\OneTimeCode;
use App\Mail\SmtpConnectionTest;
use App\Models\Episode;
use App\Models\Show;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class BrandedMailTemplateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_transactional_templates_include_pelevo_branding_and_payload(): void
    {
        $otp = (new OneTimeCode('482910', 'login'))->render();
        $this->assertStringContainsString('PELEVO', $otp);
        $this->assertStringContainsString('482910', $otp);
        $this->assertStringContainsString('sign-in code', $otp);

        $reset = (new OneTimeCode('119933', 'password_reset'))->render();
        $this->assertStringContainsString('Reset your password', $reset);
        $this->assertStringContainsString('119933', $reset);

        $claim = (new ClaimVerificationCode('554433', 'Morning Money', 'https://cdn.example.com/show.jpg', 'Ada'))->render();
        $this->assertStringContainsString('Morning Money', $claim);
        $this->assertStringContainsString('554433', $claim);
        $this->assertStringContainsString('https://cdn.example.com/show.jpg', $claim);
        $this->assertStringContainsString('Ada', $claim);

        $admin = (new AdminPasswordReset('reset-token'))->render();
        $this->assertStringContainsString('administrator password', $admin);
        $this->assertStringContainsString('reset-token', $admin);

        $test = (new SmtpConnectionTest('bulk.smtp.mailtrap.io', 2525, 'info@pelevo.com', 'Wednesday, Sep 23, 2026 1:51 PM'))->render();
        $this->assertStringContainsString('mail connection is working', $test);
        $this->assertStringContainsString('bulk.smtp.mailtrap.io:2525', $test);
        $this->assertStringContainsString('African podcasts, finally home.', $test);
    }

    public function test_new_episode_alert_includes_artwork_title_and_artist(): void
    {
        $show = Show::create(['rss_url' => 'https://example.com/brand.xml', 'title' => 'The Daily Africa', 'author' => 'Amina Bello', 'artwork_url' => 'https://cdn.example.com/daily.jpg']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'ep-mail', 'title' => 'Lagos after dark', 'audio_url' => 'https://example.com/ep.mp3', 'duration_seconds' => 1860, 'description' => 'A night walk through the city.']);
        $html = (new NewEpisodeAlert($episode->load('show')))->render();

        $this->assertStringContainsString('PELEVO', $html);
        $this->assertStringContainsString('The Daily Africa', $html);
        $this->assertStringContainsString('Lagos after dark', $html);
        $this->assertStringContainsString('Amina Bello', $html);
        $this->assertStringContainsString('https://cdn.example.com/daily.jpg', $html);
        $this->assertStringContainsString('31 min', $html);
        $this->assertStringContainsString('/episodes/'.$episode->id, $html);
    }
}
