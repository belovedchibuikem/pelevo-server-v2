<?php

namespace App\Services;

use App\Contracts\SocialIdentityVerifier;
use App\Data\SocialIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class HttpSocialIdentityVerifier implements SocialIdentityVerifier
{
    public function verify(string $provider, string $token): ?SocialIdentity
    {
        $endpoint = config("services.{$provider}.identity_url");
        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }
        $response = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(8)->get($endpoint);
        if (! $response->successful()) {
            return null;
        }
        $subject = $response->json('sub') ?? $response->json('id');
        if (! is_string($subject) || $subject === '') {
            return null;
        }
        $email = $response->json('email');
        $verified = filter_var($response->json('email_verified', false), FILTER_VALIDATE_BOOL);

        return new SocialIdentity($subject, is_string($email) ? Str::lower($email) : null, $verified, is_string($response->json('name')) ? $response->json('name') : null);
    }
}
