<?php

namespace App\Jobs;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MergeDuplicateShow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $mergeId)
    {
        $this->onQueue('catalog');
    }

    public function handle(InvalidateDiscoveryCache $cache): void
    {
        $showIds = DB::transaction(function (): array {
            $merge = DB::table('catalog_merges')->where('id', $this->mergeId)->lockForUpdate()->first();
            if (! $merge || $merge->state === 'completed') {
                return [];
            }
            DB::table('shows')->whereIn('id', [$merge->survivor_show_id, $merge->duplicate_show_id])->lockForUpdate()->get();
            DB::table('catalog_merges')->where('id', $merge->id)->update(['state' => 'processing', 'started_at' => now(), 'updated_at' => now()]);
            foreach (['category_show' => 'category_id', 'follows' => 'user_id', 'show_ratings' => 'user_id'] as $table => $otherKey) {
                DB::table($table)->where('show_id', $merge->duplicate_show_id)->orderBy($otherKey)->get()->each(function ($row) use ($table, $otherKey, $merge): void {
                    $values = ['show_id' => $merge->survivor_show_id, $otherKey => $row->{$otherKey}];
                    if ($table !== 'category_show') {
                        $values += ['created_at' => $row->created_at, 'updated_at' => now()];
                    }
                    if ($table === 'follows') {
                        $values['notifications_enabled'] = $row->notifications_enabled;
                    }
                    if ($table === 'show_ratings') {
                        $values['rating'] = $row->rating;
                    }
                    DB::table($table)->insertOrIgnore($values);
                });
                DB::table($table)->where('show_id', $merge->duplicate_show_id)->delete();
            }
            DB::table('show_reviews')->where('show_id', $merge->duplicate_show_id)->orderBy('id')->get()->each(function ($row) use ($merge): void {
                if (DB::table('show_reviews')->where('show_id', $merge->survivor_show_id)->where('user_id', $row->user_id)->exists()) {
                    DB::table('show_reviews')->where('id', $row->id)->delete();
                } else {
                    DB::table('show_reviews')->where('id', $row->id)->update(['show_id' => $merge->survivor_show_id, 'updated_at' => now()]);
                }
            });
            DB::table('episodes')->where('show_id', $merge->duplicate_show_id)->update(['show_id' => $merge->survivor_show_id, 'updated_at' => now()]);
            DB::table('reels')->where('show_id', $merge->duplicate_show_id)->update(['show_id' => $merge->survivor_show_id, 'updated_at' => now()]);
            DB::table('show_external_ids')->where('show_id', $merge->duplicate_show_id)->update(['show_id' => $merge->survivor_show_id]);
            DB::table('show_claims')->where('show_id', $merge->duplicate_show_id)->update(['show_id' => $merge->survivor_show_id, 'updated_at' => now()]);
            DB::table('claim_disputes')->where('show_id', $merge->duplicate_show_id)->update(['show_id' => $merge->survivor_show_id, 'updated_at' => now()]);
            if (DB::table('verified_show_claims')->where('show_id', $merge->duplicate_show_id)->exists()) {
                DB::table('verified_show_claims')->where('show_id', $merge->duplicate_show_id)->update(['show_id' => $merge->survivor_show_id, 'updated_at' => now()]);
            }
            DB::table('show_feed_states')->where('show_id', $merge->duplicate_show_id)->delete();
            DB::table('show_redirects')->updateOrInsert(['source_show_id' => $merge->duplicate_show_id], ['destination_show_id' => $merge->survivor_show_id, 'catalog_merge_id' => $merge->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('shows')->where('id', $merge->duplicate_show_id)->update(['status' => 'merged', 'updated_at' => now()]);
            DB::table('catalog_merges')->where('id', $merge->id)->update(['state' => 'completed', 'finished_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'admin_id' => $merge->admin_id, 'action' => 'catalog.merge_completed', 'subject_type' => 'App\\Models\\CatalogMerge', 'subject_id' => $merge->id, 'reason' => $merge->reason, 'after' => json_encode(['redirect' => [$merge->duplicate_show_id => $merge->survivor_show_id]]), 'created_at' => now(), 'updated_at' => now()]);

            return [$merge->survivor_show_id, $merge->duplicate_show_id];
        }, 3);
        foreach ($showIds as $showId) {
            $cache->show($showId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('catalog_merges')->where('id', $this->mergeId)->whereNot('state', 'completed')->update(['state' => 'failed', 'error' => mb_substr($exception?->getMessage() ?? 'Unknown merge failure.', 0, 2000), 'finished_at' => now(), 'updated_at' => now()]);
    }
}
