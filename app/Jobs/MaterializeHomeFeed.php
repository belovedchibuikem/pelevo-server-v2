<?php

namespace App\Jobs;

use App\Actions\Catalog\BuildHomeFeed;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class MaterializeHomeFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly string $userId)
    {
        $this->onQueue('feeds');
    }

    /**
     * Execute the job.
     */
    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(BuildHomeFeed $builder): void
    {
        $existing = DB::table('home_feed_snapshots')->where('user_id', $this->userId)->first();
        DB::table('home_feed_snapshots')->updateOrInsert(['user_id' => $this->userId], ['rails' => json_encode($builder->handle($this->userId), JSON_THROW_ON_ERROR), 'version' => ($existing?->version ?? 0) + 1, 'generated_at' => now(), 'expires_at' => now()->addMinutes(15), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
    }
}
