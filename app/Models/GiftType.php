<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GiftType extends Model
{
    use HasUlids;

    protected $fillable = ['slug', 'name', 'coins', 'active', 'version'];

    public static function seed(): void
    {
        $now = now();
        // Match Buy Coins pack floors: 300 coins and up.
        $gifts = [
            ['applause', 'Applause', 300],
            ['love', 'Love', 500],
            ['star', 'Star', 750],
            ['champion', 'Champion', 1000],
            ['crown', 'Crown', 1500],
            ['legend', 'Legend', 2000],
        ];
        foreach ($gifts as [$slug, $name, $coins]) {
            $existing = DB::table('gift_types')->where('slug', $slug)->first();
            if ($existing !== null) {
                DB::table('gift_types')->where('slug', $slug)->update([
                    'name' => $name,
                    'coins' => $coins,
                    'active' => true,
                    'updated_at' => $now,
                ]);

                continue;
            }
            DB::table('gift_types')->insert([
                'id' => (string) Str::ulid(),
                'slug' => $slug,
                'name' => $name,
                'coins' => $coins,
                'active' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
