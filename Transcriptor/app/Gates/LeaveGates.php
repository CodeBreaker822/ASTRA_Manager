<?php

namespace App\Gates;

use App\Models\User;
use App\Traits\Gates;
use App\Traits\HasGatePermissions;
use Illuminate\Support\Facades\Gate;

class LeaveGates
{
    use Gates, HasGatePermissions;

    /**
     * Register Employee Leave related gates
     */
    public static function register(): void
    {
        // Daily Time Record
        Gate::define('human_resource-generate_all_DTR', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-generate_all_DTR');
        });

        // User Leave Application
        Gate::define('human_resource-apply_leave', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-apply_leave');
        });

        // User Leave Application
        Gate::define('human_resource-wellness_apply_leave', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-wellness_apply_leave');
        });

        // Manage Leave Application
        Gate::define('human_resource-manage_leave', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-manage_leave');
        });

    }
}
