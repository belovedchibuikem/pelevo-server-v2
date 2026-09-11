<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MaterializeRecommendations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $userId)
    {
        $this->onQueue('recommendations');
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(): void
    {
        if (! config('features.recommendations')) {
            return;
        }
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        $followed = DB::table('follows')->where('user_id', $user->id)->pluck('show_id');
        $items = DB::table('episodes')->whereIn('show_id', $followed)->orderByDesc('published_at')->limit(30)->get(['id', 'show_id', 'title', 'published_at'])->map(fn ($row) => [...(array) $row, 'raw_rank' => 1, 'adjusted_rank' => 1, 'reason' => 'Because you follow this show'])->values();
        if ($items->isEmpty()) {
            $items = DB::table('episodes')->orderByDesc('published_at')->limit(30)->get(['id', 'show_id', 'title', 'published_at'])->map(fn ($row) => [...(array) $row, 'raw_rank' => .8, 'adjusted_rank' => 1, 'reason' => 'Trending on Pelevo'])->values();
        }
        $version = (int) DB::table('recommendation_snapshots')->where('user_id', $user->id)->max('version') + 1;
        DB::table('recommendation_snapshots')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'version' => $version, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'explanations' => json_encode($items->pluck('reason'), JSON_THROW_ON_ERROR), 'expires_at' => now()->addHours(6), 'created_at' => now(), 'updated_at' => now()]);
    }
}
