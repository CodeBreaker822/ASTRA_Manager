<?php

namespace App\Gates;

use App\Models\User;
use App\Traits\Gates;
use App\Traits\HasGatePermissions;
use Illuminate\Support\Facades\Gate;

class CmsGates
{
    use Gates, HasGatePermissions;

    public static function register(): void
    {
        Gate::define('cms.view', function (User $user): bool {
            return self::checkAnyPermission($user, [
                'cms.view',
                'cms.manage-blog',
                'cms.manage-pricing',
                'cms.manage-pages',
            ]);
        });

        Gate::define('cms.manage-blog', function (User $user): bool {
            return self::checkPermission($user, 'cms.manage-blog');
        });

        Gate::define('cms.manage-pricing', function (User $user): bool {
            return self::checkPermission($user, 'cms.manage-pricing');
        });

        Gate::define('cms.manage-pages', function (User $user): bool {
            return self::checkPermission($user, 'cms.manage-pages');
        });
    }
}
