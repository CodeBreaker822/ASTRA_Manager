<?php

namespace App\Gates;

use App\Models\User;
use App\Traits\Gates;
use App\Traits\HasGatePermissions;
use Illuminate\Support\Facades\Gate;

class APIManagerGates
{
    use Gates, HasGatePermissions;

    /**
     * Register API related gates
     */
    public static function register(): void
    {
        Gate::define('API-manage_api', function (User $user): bool {
            return self::checkPermission($user, 'API-manage_api');
        });
    }
}
