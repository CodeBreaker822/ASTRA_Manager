<?php

namespace Database\Seeders;

use App\Models\UserPermissions;
use App\Models\UserPositions;
use Illuminate\Database\Seeder;

class CmsPositionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPosition('CONTENT_EDITOR', 'Content Editor', [
            'cms.view',
            'cms.manage-blog',
        ]);

        $this->seedPosition('CONTENT_MANAGER', 'Content Manager', [
            'cms.view',
            'cms.manage-blog',
            'cms.manage-pricing',
            'cms.manage-pages',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function seedPosition(string $code, string $name, array $permissions): void
    {
        $position = UserPositions::query()->updateOrCreate(
            ['position_code' => $code],
            [
                'position_name' => $name,
                'assigned_office' => 'JERVA Web',
                'category' => 'CMS',
                'description' => $name.' dashboard permissions.',
                'max_users' => 0,
                'is_active' => true,
            ],
        );

        foreach ($permissions as $permission) {
            UserPermissions::query()->firstOrCreate([
                'position_id' => $position->id,
                'permission_name' => $permission,
            ]);
        }
    }
}
