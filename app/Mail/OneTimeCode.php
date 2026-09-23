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
        return new Envelope(
            subject: $this->purpose === 'password_reset'
                ? 'Reset your Pelevo password'
                : 'Your Pelevo sign-in code',
        );
    }

    public function content(): Content
    {
        $login = $this->purpose !== 'password_reset';

        return new Content(
            html: 'mail.otp',
            text: 'mail.otp-text',
            with: [
                'preheader' => $login
                    ? 'Your Pelevo sign-in code expires in 10 minutes.'
                    : 'Use this code in Pelevo to choose a new password.',
                'eyebrow' => $login ? 'Sign in' : 'Password reset',
                'heading' => $login ? 'Your sign-in code' : 'Reset your password',
                'intro' => $login
                    ? 'Enter this code in the Pelevo app to finish signing in.'
                    : 'Enter this code in the Pelevo app to choose a new password.',
                'hint' => $login
                    ? 'Open Pelevo and type the code on the verification screen.'
                    : 'Open Pelevo, choose forgot password, and type the code when asked.',
            ],
        );
    }
}
