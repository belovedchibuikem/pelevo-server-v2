<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPasswordReset extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Pelevo administrator password',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.admin-reset',
            text: 'mail.admin-reset-text',
            with: [
                'resetUrl' => route('admin.password.reset', ['token' => $this->token]),
            ],
        );
    }
}
