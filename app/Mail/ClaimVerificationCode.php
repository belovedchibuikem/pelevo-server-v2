<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClaimVerificationCode extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $showTitle,
        public readonly ?string $artworkUrl = null,
        public readonly ?string $author = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your Pelevo claim for '.$this->showTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.claim',
            text: 'mail.claim-text',
            with: [
                'artworkUrl' => $this->httpsUrl($this->artworkUrl),
            ],
        );
    }

    private function httpsUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '' || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
    }
}
