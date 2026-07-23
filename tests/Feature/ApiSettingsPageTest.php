<?php

use App\Models\API;
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

test('api manager page does not expose stored license keys', function () {
    $this->withoutVite();

    $user = createApiSettingsUserWithPermissions(['API-manage_api']);
    API::query()->create([
        'app_name' => 'private-client',
        'app_token' => 'is_license_private_token_for_test',
        'can_post' => true,
        'can_get' => true,
    ]);

    $this->actingAs($user)
        ->get(route('api.manager'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('apis.0.app_name', 'private-client')
            ->missing('apis.0.app_token')
            ->where('apis.0.token_suffix', 'ken_for_test'));
});

test('api manager page only lists global tokens', function () {
    $this->withoutVite();

    $manager = createApiSettingsUserWithPermissions(['API-manage_api']);
    $managedUser = User::factory()->create();

    API::query()->create([
        'user_id' => $managedUser->id,
        'app_name' => 'web-user-token',
        'app_token' => 'is_license_user_token_for_test',
        'can_post' => true,
        'can_get' => true,
    ]);

    API::query()->create([
        'app_name' => 'global-client',
        'app_token' => 'is_license_global_token_for_test',
        'can_post' => true,
        'can_get' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('api.manager'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Api')
            ->has('apis', 1)
            ->where('apis.0.app_name', 'global-client'));
});

test('api manager endpoints cannot mutate user tokens', function () {
    $manager = createApiSettingsUserWithPermissions(['API-manage_api']);
    $managedUser = User::factory()->create();
    $userToken = API::query()->create([
        'user_id' => $managedUser->id,
        'app_name' => 'web-user-token',
        'app_token' => 'is_license_user_token_for_endpoint_test',
        'can_post' => true,
        'can_get' => true,
    ]);

    $this->actingAs($manager)
        ->putJson(route('api.update-status', $userToken), ['is_active' => false])
        ->assertNotFound();

    expect($userToken->refresh()->is_active)->toBeTrue();
});

test('new api license key is returned only as a one time token', function () {
    $user = createApiSettingsUserWithPermissions(['API-manage_api']);

    $response = $this->actingAs($user)
        ->postJson(route('api.store'), [
            'app_name' => 'one-time-client',
            'can_post' => true,
            'can_get' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.app_name', 'one-time-client')
        ->assertJsonMissingPath('data.app_token');

    expect($response->json('plain_token'))->toStartWith('is_license_');
});

test('user management edit payload only includes the masked user generated api token', function () {
    $this->withoutVite();

    $manager = createApiSettingsUserWithPermissions(['user.manage-users']);
    $managedUser = User::factory()->create();

    API::query()->create([
        'user_id' => $managedUser->id,
        'app_name' => 'web-user-token',
        'app_token' => 'is_license_visible_on_user_edit',
        'can_post' => true,
        'can_get' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('dashboard.users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Users')
            ->where('users.1.id', $managedUser->id)
            ->where('users.1.license.app_name', 'web-user-token')
            ->missing('users.1.license.app_token')
            ->where('users.1.license.token_suffix', '_edit')
            ->where('users.1.license.masked_token', '********************_edit'));
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
