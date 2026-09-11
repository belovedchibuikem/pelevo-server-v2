<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlaybackProgress extends Model
{
    protected $fillable = ['user_id', 'episode_id', 'device_id', 'position_seconds', 'completed', 'version'];

    protected function casts(): array
    {
        return ['completed' => 'boolean'];
    }
}
