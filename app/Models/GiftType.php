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
        $gifts = [
            ['applause', 'Applause', 20],
            ['love', 'Love', 50],
            ['star', 'Star', 100],
            ['champion', 'Champion', 250],
            ['crown', 'Crown', 500],
            ['legend', 'Legend', 1000],
        ];
        foreach ($gifts as [$slug, $name, $coins]) {
            if (DB::table('gift_types')->where('slug', $slug)->exists()) {
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
