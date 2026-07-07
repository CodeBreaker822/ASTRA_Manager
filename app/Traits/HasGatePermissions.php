<?php

namespace App\Traits;

use App\Models\User;
use App\Models\UserPermissions;

trait HasGatePermissions
{
    /**
     * Check if user has a specific permission
     */
    protected static function checkPermission(User $user, string $permission): bool
    {
        $positionId = $user->position_id;

        if ($positionId === null) {
            self::logUnauthorizedAccess($permission, request()->fullUrl());

            return false;
        }

        $allowed = UserPermissions::where('position_id', $positionId)
            ->where('permission_name', $permission)
            ->exists();

        if (! $allowed) {
            self::logUnauthorizedAccess($permission, request()->fullUrl());
        }

        return $allowed;
    }

    /**
     * Check if user has any of the specified permissions
     */
    protected static function checkAnyPermission(User $user, array $permissions): bool
    {
        $positionId = $user->position_id;

        if ($positionId === null) {
            self::logUnauthorizedAccess(implode('|', $permissions), request()->fullUrl());

            return false;
        }

        $allowed = UserPermissions::where('position_id', $positionId)
            ->whereIn('permission_name', $permissions)
            ->exists();

        if (! $allowed) {
            self::logUnauthorizedAccess(implode('|', $permissions), request()->fullUrl());
        }

        return $allowed;
    }

    /**
     * Check if user has all of the specified permissions
     */
    protected static function checkAllPermissions(User $user, array $permissions): bool
    {
        $positionId = $user->position_id;

        if ($positionId === null) {
            self::logUnauthorizedAccess(implode('|', $permissions), request()->fullUrl());

            return false;
        }

        $userPermissions = UserPermissions::where('position_id', $positionId)
            ->whereIn('permission_name', $permissions)
            ->pluck('permission_name')
            ->toArray();

        $hasAll = count(array_diff($permissions, $userPermissions)) === 0;

        if (! $hasAll) {
            self::logUnauthorizedAccess(implode('|', $permissions), request()->fullUrl());
        }

        return $hasAll;
    }
}
