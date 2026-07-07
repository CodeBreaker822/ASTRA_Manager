<?php

namespace App\Gates;

use App\Models\User;
use App\Traits\Gates;
use App\Traits\HasGatePermissions;
use Illuminate\Support\Facades\Gate;

class InventoryGates
{
    use Gates, HasGatePermissions;

    /**
     * Register Inventory/Asset related gates
     */
    public static function register(): void
    {
        // Main inventory module access
        Gate::define('inventory.view', function (User $user): bool {
            return self::checkPermission($user, 'inventory.view');
        });

        // Equipments submenu access
        Gate::define('inventory.manage', function (User $user): bool {
            return self::checkPermission($user, 'inventory.manage');
        });

    }
}
