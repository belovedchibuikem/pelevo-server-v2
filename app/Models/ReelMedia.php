<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ReelMedia extends Model
{
    use HasUlids;

    protected $table = 'reel_media';

    protected $fillable = ['reel_id', 'media_upload_id', 'mime', 'duration_ms', 'width', 'height', 'processing_state', 'thumbnail_path'];
}
