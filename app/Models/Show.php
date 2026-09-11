<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Show extends Model
{
    use HasUlids;

    protected $fillable = ['rss_url', 'title', 'description', 'artwork_url', 'author', 'language', 'country_code', 'explicit', 'status'];

    public function setRssUrlAttribute(string $value): void
    {
        $this->attributes['rss_url'] = $value;
        $this->attributes['rss_url_hash'] = hash('sha256', $value);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function feedState(): HasOne
    {
        return $this->hasOne(ShowFeedState::class);
    }
}
