<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SmtpConnectionTest extends Mailable
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $fromAddress,
        public readonly string $sentAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Pelevo mail connection is working',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.smtp-test',
            text: 'mail.smtp-test-text',
        );
    }
}
