<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class GiftType extends Model
{
    use HasUlids;

    protected $fillable = ['slug', 'name', 'coins', 'active', 'version'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
