<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class FinancialAccount extends Model
{
    use HasUlids;

    protected $fillable = ['owner_type', 'owner_id', 'type', 'unit', 'balance'];
}
