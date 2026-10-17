<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OneTimeCode extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly string $purpose)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->purpose) {
            'password_reset' => 'Reset your Pelevo password',
            'email_verification' => 'Verify your Pelevo email',
            default => 'Your Pelevo sign-in code',
        });
    }

    public function content(): Content
    {
        [$preheader, $eyebrow, $heading, $intro, $hint] = match ($this->purpose) {
            'password_reset' => [
                'Use this code in Pelevo to choose a new password.',
                'Password reset',
                'Reset your password',
                'Enter this code in the Pelevo app to choose a new password.',
                'Open Pelevo, choose forgot password, and type the code when asked.',
            ],
            'email_verification' => [
                'Your Pelevo verification code expires in 10 minutes.',
                'Verify email',
                'Confirm your email',
                'Enter this code in the Pelevo app to activate your account.',
                'Open Pelevo and type the code on the verification screen. Your account stays inactive until this is confirmed.',
            ],
            default => [
                'Your Pelevo sign-in code expires in 10 minutes.',
                'Sign in',
                'Your sign-in code',
                'Enter this code in the Pelevo app to finish signing in.',
                'Open Pelevo and type the code on the verification screen.',
            ],
        };

        return new Content(
            html: 'mail.otp',
            text: 'mail.otp-text',
            with: [
                'preheader' => $preheader,
                'eyebrow' => $eyebrow,
                'heading' => $heading,
                'intro' => $intro,
                'hint' => $hint,
            ],
        );
    }
}
