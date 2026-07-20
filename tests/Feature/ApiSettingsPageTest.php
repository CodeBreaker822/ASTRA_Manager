<?php

use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use Inertia\Testing\AssertableInertia;

test('api settings page requires api manager permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('api.manager'))
        ->assertForbidden();
});

test('api managers can open api management from the dashboard surface', function () {
    $this->withoutVite();

    $user = createApiSettingsUserWithPermissions(['API-manage_api']);

    $this->actingAs($user)
        ->get(route('api.manager'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Api')
            ->has('apis')
            ->has('transcriptionProviders')
            ->has('transcriberPackage'));
});

test('configured admin can open api settings through gate bypass', function () {
    $this->withoutVite();

    config([
        'admin.email' => 'work.jgnc@gmail.com',
        'admin.access' => true,
    ]);

    $user = User::factory()->create(['email' => 'work.jgnc@gmail.com']);

    $this->actingAs($user)
        ->get(route('api.manager'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Api'));
});

function createApiSettingsUserWithPermissions(array $permissions): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_API_SETTINGS',
        'position_name' => 'Test API Settings',
        'assigned_office' => 'web',
        'category' => 'api',
        'description' => 'Test API settings position',
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
