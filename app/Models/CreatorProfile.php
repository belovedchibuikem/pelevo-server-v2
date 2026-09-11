<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class CreatorProfile extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'display_name', 'status'];
}
