<?php

namespace App\Actions\Catalog;

use Illuminate\Support\Facades\DB;

final class ConsumeHomeDailyPick
{
    public function __construct(private readonly InvalidateDiscoveryCache $cache) {}

    public function handle(string $userId, ?string $episodeId): void
    {
        if ($episodeId === null || $episodeId === '') {
            return;
        }

        $updated = DB::table('home_daily_picks')
            ->where('user_id', $userId)
            ->where('episode_id', $episodeId)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $this->cache->user($userId);
        }
    }
}
