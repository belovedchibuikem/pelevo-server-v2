<?php

namespace App\Console\Commands;

use App\Jobs\HydrateRssFeed;
use App\Models\Show;
use Illuminate\Console\Command;

final class HydrateShowCommand extends Command
{
    protected $signature = 'pelevo:hydrate-show
        {show? : Show ULID or exact title}
        {--sync : Run hydration in this process instead of the rss queue}';

    protected $description = 'Queue (or run) RSS episode hydration for a discovered show.';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('show'));
        if ($needle === '') {
            $this->error('Pass a show id or exact title, e.g. pelevo:hydrate-show "Wardrobe Memo"');

            return self::FAILURE;
        }

        $show = Show::query()
            ->when(
                strlen($needle) === 26,
                fn ($query) => $query->whereKey($needle),
                fn ($query) => $query->where('title', $needle),
            )
            ->first();

        if (! $show) {
            $this->error('Show not found.');

            return self::FAILURE;
        }

        $show->feedState()->firstOrCreate([], [
            'state' => 'pending',
            'consecutive_failures' => 0,
            'next_poll_at' => now(),
        ]);

        if ($this->option('sync')) {
            $this->info("Hydrating {$show->title} ({$show->id}) synchronously…");
            dispatch_sync(new HydrateRssFeed($show->id));
        } else {
            HydrateRssFeed::dispatch($show->id);
            $this->info("Queued HydrateRssFeed for {$show->title} ({$show->id}) on the rss queue.");
            $this->line('Ensure Horizon is running: php artisan horizon:status');
        }

        $count = $show->episodes()->count();
        $this->line("Episodes now in DB: {$count}");
        $this->line('Feed state: '.($show->feedState()->value('state') ?? 'none'));

        return self::SUCCESS;
    }
}
