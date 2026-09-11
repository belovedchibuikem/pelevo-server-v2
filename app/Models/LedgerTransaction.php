<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class LedgerTransaction extends Model
{
    use HasUlids;

    protected $fillable = ['reference', 'event_type', 'idempotency_key', 'reverses_id', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
