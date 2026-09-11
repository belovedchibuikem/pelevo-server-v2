<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class Admin extends Authenticatable
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'mfa_secret' => 'encrypted', 'mfa_confirmed_at' => 'immutable_datetime', 'notification_preferences' => 'array'];
    }
}
