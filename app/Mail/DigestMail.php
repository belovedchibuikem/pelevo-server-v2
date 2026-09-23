<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{title: string, body: string}>  $items
     */
    public function __construct(public readonly array $items, public readonly string $when)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Pelevo summary');
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.digest',
            text: 'mail.digest-text',
            with: [
                'preheader' => 'Your Pelevo summary for '.$this->when.'.',
                'eyebrow' => 'Scheduled summary',
                'heading' => 'Catch up on Pelevo',
                'when' => $this->when,
                'items' => $this->items,
                'footerNote' => 'You received this because Email Notifications and a scheduled summary are on in Pelevo. Turn email off in Settings to stop these messages.',
            ],
        );
    }
}
