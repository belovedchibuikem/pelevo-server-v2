<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class SignupCountry
{
    public function capture(User $user, Request $request, ?string $submitted = null): void
    {
        if ($this->normalize($user->country_code) !== null) {
            return;
        }
        $code = $this->normalize($submitted) ?? $this->fromConnection($request);
        if ($code === null) {
            return;
        }
        $user->forceFill(['country_code' => $code])->save();
    }

    public function normalize(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }

    private function fromConnection(Request $request): ?string
    {
        $region = app(EarnRegion::class);
        $observed = $region->observedCountry($request);
        if ($observed !== null || app()->runningUnitTests()) {
            return $observed;
        }

        return $region->country($request);
    }
}
