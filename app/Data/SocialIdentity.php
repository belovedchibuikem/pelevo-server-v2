<?php

namespace App\Data;

final readonly class SocialIdentity
{
    public function __construct(public string $subject, public ?string $email, public bool $emailVerified, public ?string $name = null) {}
}
