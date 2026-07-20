<?php

namespace App\Services;

use App\Models\User;

class DashboardAccessService
{
    public function canAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isConfiguredAdmin($user) || $this->hasAnyAssignedPermission($user);
    }

    public function isConfiguredAdmin(User $user): bool
    {
        if (! filter_var(config('admin.access'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $adminEmail = trim((string) config('admin.email'));

        return $adminEmail !== '' && strcasecmp($user->email, $adminEmail) === 0;
    }

    private function hasAnyAssignedPermission(User $user): bool
    {
        if ($user->position_id === null) {
            return false;
        }

        return $user->position()
            ->where('is_active', true)
            ->whereHas('permissions')
            ->exists();
    }
}
