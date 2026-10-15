<?php

namespace App\Integrations\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class EpisodeBrief
{
    public function __construct(
        public readonly string $title,
        public readonly string $showTitle,
        public readonly string $author,
        public readonly string $description,
        public readonly string $transcript,
    ) {}

    public static function for(?string $episodeId): self
    {
        if ($episodeId === null || $episodeId === '') {
            return new self('Untitled episode', '', '', '', '');
        }
        $episode = DB::table('episodes')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('episodes.id', $episodeId)
            ->first([
                'episodes.title',
                'episodes.description',
                'shows.title as show_title',
                'shows.author',
            ]);
        $transcript = '';
        if (Schema::hasTable('episode_transcripts')) {
            $content = DB::table('episode_transcripts')
                ->where('episode_id', $episodeId)
                ->where('state', 'available')
                ->orderByDesc('id')
                ->value('content');
            if (is_string($content)) {
                $transcript = Str::limit(trim(strip_tags($content)), 12000, '');
            }
        }

        return new self(
            is_object($episode) ? (string) $episode->title : 'Untitled episode',
            is_object($episode) ? (string) ($episode->show_title ?? '') : '',
            is_object($episode) ? (string) ($episode->author ?? '') : '',
            is_object($episode) ? Str::limit(trim(strip_tags((string) ($episode->description ?? ''))), 6000, '') : '',
            $transcript,
        );
    }

    public function asText(): string
    {
        $parts = ["Title: {$this->title}"];
        if ($this->showTitle !== '') {
            $parts[] = "Show: {$this->showTitle}";
        }
        if ($this->author !== '') {
            $parts[] = "Host: {$this->author}";
        }
        if ($this->description !== '') {
            $parts[] = "Description:\n{$this->description}";
        }
        if ($this->transcript !== '') {
            $parts[] = "Transcript excerpt:\n{$this->transcript}";
        }
        if ($this->description === '' && $this->transcript === '') {
            $parts[] = 'No description or transcript is stored for this episode. Say what you can from the title and show, and do not invent quotes or facts.';
        }

        return implode("\n\n", $parts);
    }
}
