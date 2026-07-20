<?php

use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use Illuminate\Support\Facades\Gate;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users without dashboard gates are redirected to workspace', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('workspace.index', absolute: false));
});

test('cms gates are registered', function () {
    expect(array_keys(Gate::abilities()))->toContain(
        'cms.view',
        'cms.manage-blog',
        'cms.manage-pricing',
        'cms.manage-pages',
    );
});

test('content editors can access only blog dashboard sections', function () {
    $user = createDashboardUserWithPermissions(['cms.manage-blog']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('dashboard.blog.index'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('dashboard.pricing.edit'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('dashboard.pages.features.edit'))
        ->assertForbidden();
});

test('users with any assigned gate permission can access dashboard overview', function () {
    $user = createDashboardUserWithPermissions(['certificates.view']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('super admin bypass can access dashboard sections when enabled', function () {
    config([
        'admin.email' => 'admin@example.com',
        'admin.access' => true,
    ]);

    $user = User::factory()->create(['email' => 'admin@example.com']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('dashboard.blog.index'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('dashboard.pricing.edit'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('dashboard.pages.download.edit'))
        ->assertOk();
});

test('super admin bypass does not apply when disabled', function () {
    config([
        'admin.email' => 'admin@example.com',
        'admin.access' => false,
    ]);

    $user = User::factory()->create(['email' => 'admin@example.com']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('workspace.index', absolute: false));

    $this->actingAs($user)
        ->get(route('dashboard.blog.index'))
        ->assertForbidden();
});

function createDashboardUserWithPermissions(array $permissions): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_DASHBOARD',
        'position_name' => 'Test Dashboard',
        'assigned_office' => 'web',
        'category' => 'cms',
        'description' => 'Test dashboard position',
        'is_active' => true,
    ]);

    foreach ($permissions as $permission) {
        UserPermissions::query()->create([
            'position_id' => $position->id,
            'permission_name' => $permission,
        ]);
    }

    return User::factory()->create([
        'position_id' => $position->id,
    ]);
}
