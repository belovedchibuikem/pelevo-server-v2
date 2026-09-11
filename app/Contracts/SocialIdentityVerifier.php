<?php

namespace App\Contracts;

use App\Data\SocialIdentity;

interface SocialIdentityVerifier
{
    public function verify(string $provider, string $token): ?SocialIdentity;
}
