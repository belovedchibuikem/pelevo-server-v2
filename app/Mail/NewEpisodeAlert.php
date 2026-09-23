<?php

namespace App\Mail;

use App\Models\Episode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class NewEpisodeAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Episode $episode) {}

    public function envelope(): Envelope
    {
        $this->episode->loadMissing('show');
        $show = $this->episode->show?->title ?: 'a show you follow';

        return new Envelope(
            subject: 'New from '.$show.': '.$this->episode->title,
        );
    }

    public function content(): Content
    {
        $this->episode->loadMissing('show');
        $show = $this->episode->show;
        $description = trim(strip_tags((string) $this->episode->description));

        return new Content(
            html: 'mail.new-episode',
            text: 'mail.new-episode-text',
            with: [
                'preheader' => Str::limit($this->episode->title.' is now on Pelevo.', 90),
                'heading' => 'A new episode just dropped',
                'showTitle' => $show?->title ?: 'Pelevo',
                'author' => $show?->author,
                'episodeTitle' => $this->episode->title,
                'artworkUrl' => $this->httpsUrl($show?->artwork_url),
                'duration' => $this->durationLabel($this->episode->duration_seconds),
                'description' => $description === '' ? null : Str::limit($description, 220),
                'listenUrl' => route('share.episode', $this->episode),
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

    private function durationLabel(mixed $seconds): ?string
    {
        $value = is_numeric($seconds) ? (int) $seconds : 0;
        if ($value < 1) {
            return null;
        }
        $minutes = intdiv($value, 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' hr '.($minutes % 60).' min';
    }
}
