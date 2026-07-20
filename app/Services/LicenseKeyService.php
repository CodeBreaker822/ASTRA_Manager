<?php

namespace App\Services;

use App\Models\API;
use App\Models\User;
use Illuminate\Support\Str;

class LicenseKeyService
{
    public function makeUniqueLicenseKey(): string
    {
        do {
            $key = 'is_license_'.bin2hex(random_bytes(48));
        } while (API::query()->where('app_token', $key)->exists());

        return $key;
    }

    public function provisionForUser(User $user): API
    {
        $license = $user->license()->first();

        if ($license) {
            return $license;
        }

        return $user->license()->create([
            'app_name' => 'web-user-'.Str::uuid(),
            'app_token' => $this->makeUniqueLicenseKey(),
            'can_post' => true,
            'can_get' => true,
            'can_put' => false,
            'can_patch' => false,
            'can_delete' => false,
            'is_active' => ! in_array($user->user_status, ['banned', 'deactivated'], true),
        ]);
    }

    public function syncStatusForUser(User $user): void
    {
        $license = $this->provisionForUser($user);

        $license->update([
            'is_active' => ! in_array($user->user_status, ['banned', 'deactivated'], true),
        ]);
    }
}
