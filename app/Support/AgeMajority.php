<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class AgeMajority
{
    public static function missing(?object $user): ?JsonResponse
    {
        if ($user !== null && $user->age_majority_confirmed_at !== null) {
            return null;
        }

        return ApiResponse::error(
            'AGE_CONFIRMATION_REQUIRED',
            'Confirm you are 18 years or older, or the age of majority in your country, before continuing.',
            403,
        );
    }
}
