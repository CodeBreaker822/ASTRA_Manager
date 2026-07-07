<?php

namespace App\Gates;

use App\Models\User;
use App\Traits\Gates;
use App\Traits\HasGatePermissions;
use Illuminate\Support\Facades\Gate;

class UserGates
{
    use Gates, HasGatePermissions;

    /**
     * Register User related gates
     */
    public static function register(): void
    {
        Gate::define('user.manage-users', function (User $user): bool {
            return self::checkAnyPermission($user, ['user.manage-users', 'user.manage-profiles']);
        });

        Gate::define('user.manage-permissions', function (User $user): bool {
            return self::checkPermission($user, 'user.manage-permissions');
        });

        // Certificate Management Gates
        Gate::define('certificates.view', function (User $user): bool {
            return self::checkPermission($user, 'certificates.view');
        });

        Gate::define('certificates.edit', function (User $user): bool {
            return self::checkPermission($user, 'certificates.edit');
        });

        Gate::define('certificates.templates', function (User $user): bool {
            return self::checkAnyPermission($user, ['certificates.templates', 'certificates.edit']);
        });
    }
}
