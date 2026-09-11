<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ShowFeedState extends Model
{
    protected $primaryKey = 'show_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['show_id', 'etag', 'last_modified', 'content_hash', 'last_success_at', 'last_failure_at', 'consecutive_failures', 'next_poll_at', 'state', 'last_error'];

    protected function casts(): array
    {
        return ['last_success_at' => 'immutable_datetime', 'last_failure_at' => 'immutable_datetime', 'next_poll_at' => 'immutable_datetime'];
    }
}
