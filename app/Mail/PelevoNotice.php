<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

class PelevoNotice extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $eyebrow,
        public readonly string $heading,
        public readonly string $intro,
        public readonly ?string $detail = null,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
        public readonly ?string $footerNote = null,
        public readonly ?string $preheader = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.notice',
            text: 'mail.notice-text',
            with: [
                'preheader' => $this->preheader ?? Str::limit($this->intro, 90),
                'eyebrow' => $this->eyebrow,
                'heading' => $this->heading,
                'intro' => $this->intro,
                'detail' => $this->detail,
                'actionLabel' => $this->actionLabel,
                'actionUrl' => $this->httpsUrl($this->actionUrl),
                'footerNote' => $this->footerNote,
            ],
        );
    }

    private function httpsUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            return null;
        }

        return $url;
    }
}
