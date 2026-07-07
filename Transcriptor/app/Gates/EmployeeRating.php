<?php

namespace App\Gates;

use App\Models\User;
use App\Traits\Gates;
use App\Traits\HasGatePermissions;
use Illuminate\Support\Facades\Gate;

class EmployeeRating
{
    use Gates, HasGatePermissions;

    public static function register(): void
    {

        Gate::define('human_resource-employee_rating_management_OPCR', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-employee_rating_management_OPCR');
        });
        Gate::define('human_resource-employee_rating_management_DPCR', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-employee_rating_management_DPCR')
                || self::checkPermission($user, 'human_resource-employee_rating_management_DPRC');
        });
        Gate::define('human_resource-employee_rating_management_DPRC', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-employee_rating_management_DPRC');
        });
        Gate::define('human_resource-employee_rating_management_IPCR', function (User $user): bool {
            return self::checkPermission($user, 'human_resource-employee_rating_management_IPCR');
        });

    }
}
