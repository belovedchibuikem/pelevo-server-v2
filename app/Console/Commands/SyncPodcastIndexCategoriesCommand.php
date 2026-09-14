<?php

namespace App\Console\Commands;

use App\Actions\Catalog\SyncPodcastIndexCategories;
use Illuminate\Console\Command;

final class SyncPodcastIndexCategoriesCommand extends Command
{
    protected $signature = 'podcast-index:sync-categories';

    protected $description = 'Sync Podcast Index categories into the local browse taxonomy';

    public function handle(SyncPodcastIndexCategories $sync): int
    {
        $count = $sync->handle();
        $this->info("Synced {$count} Podcast Index categories.");

        return self::SUCCESS;
    }
}
