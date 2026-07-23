<?php

namespace App\Services\Security;

use Illuminate\Support\Str;

class LicenseTokenFingerprint
{
    public function prefix(?string $token): ?string
    {
        return is_string($token) && $token !== '' ? Str::limit($token, 24, '') : null;
    }

    public function hash(?string $token): ?string
    {
        return is_string($token) && $token !== '' ? hash('sha256', $token) : null;
    }
}
