<?php

namespace App\Actions\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class InvalidateDiscoveryCache
{
    public function user(string $userId): void
    {
        Cache::forget("home:feed:{$userId}:v1");
        Cache::forget("home:feed:{$userId}:v2");
        DB::table('home_feed_snapshots')->where('user_id', $userId)->delete();
    }

    public function public(): void
    {
        foreach (['home:discover:v1', 'home:charts:v1', 'home:playlists:v1', 'browse:index:v1'] as $key) {
            Cache::forget($key);
        }
    }

    public function show(string $showId): void
    {
        $this->public();
        DB::table('follows')->where('show_id', $showId)->orderBy('user_id')->pluck('user_id')->each(function (string $userId): void {
            $this->user($userId);
        });
    }
}
