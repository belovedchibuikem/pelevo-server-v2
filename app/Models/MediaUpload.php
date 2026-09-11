<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class MediaUpload extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'disk', 'path', 'expected_mime', 'expected_size', 'actual_size', 'checksum_sha256', 'state', 'probe', 'failure_reason', 'expires_at', 'uploaded_at', 'processed_at'];

    protected function casts(): array
    {
        return ['probe' => 'array', 'expires_at' => 'immutable_datetime', 'uploaded_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }
}
