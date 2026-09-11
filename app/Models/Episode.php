<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Episode extends Model
{
    use HasUlids;

    protected $fillable = ['show_id', 'guid', 'external_id', 'title', 'description', 'audio_url', 'duration_seconds', 'published_at', 'availability'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }
}
