<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class PremiumAccess
{
    public const FREE_DOWNLOAD_LIMIT = 10;

    public static function active(string $userId): bool
    {
        return DB::table('premium_entitlements')
            ->where('user_id', $userId)
            ->where('state', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->exists();
    }
}
