<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class CreatorAccess
{
    public static function granted(string $userId): bool
    {
        return DB::table('creator_profiles')
            ->join('show_claims', 'show_claims.creator_profile_id', '=', 'creator_profiles.id')
            ->join('verified_show_claims', 'verified_show_claims.show_claim_id', '=', 'show_claims.id')
            ->where('creator_profiles.user_id', $userId)
            ->exists();
    }

    /**
     * @return array{creator_access: bool}
     */
    public static function capabilities(string $userId): array
    {
        return ['creator_access' => self::granted($userId)];
    }
}
