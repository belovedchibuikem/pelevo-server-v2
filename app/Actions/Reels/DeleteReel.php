<?php

namespace App\Actions\Reels;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Removes a reel from the product. Ad attribution rows restrict the reel
 * foreign key, so a reel that has earned an impression is retired in place.
 * The attribution stays, which keeps pending and posted ad revenue intact.
 */
final class DeleteReel
{
    public function handle(string $reelId, ?string $creatorProfileId = null): string
    {
        return DB::transaction(function () use ($reelId, $creatorProfileId): string {
            $query = DB::table('reels')->where('id', $reelId);
            if ($creatorProfileId !== null) {
                $query->where('creator_profile_id', $creatorProfileId);
            }
            $reel = $query->lockForUpdate()->first();
            if (! $reel || $reel->state === 'deleted') {
                return 'missing';
            }

            $attributed = DB::table('ad_impression_attributions')->where('reel_id', $reelId)->exists();
            if ($attributed) {
                DB::table('reels')->where('id', $reelId)->update([
                    'state' => 'deleted',
                    'media_url' => null,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('reels')->where('id', $reelId)->delete();
            }

            Cache::forget('reel:'.$reelId);

            return 'deleted';
        });
    }
}
