<?php

use App\Models\API;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;

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
        ->assertViewIs('dashboard.api')
        ->assertViewHas('apis')
        ->assertViewHas('transcriptionProviders')
        ->assertViewHas('transcriberPackage');
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
        ->assertViewIs('dashboard.api');
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
        ->assertViewHas('apis', fn ($apis): bool => data_get($apis, '0.app_name') === 'private-client'
            && ! array_key_exists('app_token', (array) data_get($apis, '0'))
            && data_get($apis, '0.token_suffix') === 'ken_for_test');
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
        ->assertViewIs('dashboard.api')
        ->assertViewHas('apis', fn ($apis): bool => count($apis) === 1
            && data_get($apis, '0.app_name') === 'global-client');
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

    $response = $this->actingAs($manager)
        ->get(route('dashboard.users.index'))
        ->assertOk()
        ->assertViewIs('dashboard.users');

    $managed = collect($response->viewData('users'))
        ->firstWhere('id', $managedUser->id);

    expect($managed)->not->toBeNull();
    expect($managed['license']['app_name'])->toBe('web-user-token');
    expect($managed['license'])->not->toHaveKey('app_token');
    expect($managed['license']['token_suffix'])->toBe('_edit');
    expect($managed['license']['masked_token'])->toBe('********************_edit');
});

test('provider sections split the catalog by category and connection state', function () {
    $this->withoutVite();

    $response = $this->actingAs(createApiSettingsUserWithPermissions(['API-manage_api']))
        ->get(route('api.manager'))
        ->assertOk();

    $sections = collect($response->viewData('providerSections'))->keyBy('category');

    expect($sections->keys()->all())->toBe(['transcriber', 'text_fixer']);

    foreach ($sections as $category => $section) {
        // A provider belongs to exactly one panel, on exactly one side of it.
        foreach ($section['configured'] as $provider) {
            expect($provider['category'])->toBe($category)
                ->and($provider['configured'])->toBeTrue();
        }

        foreach ($section['available'] as $provider) {
            expect($provider['category'])->toBe($category)
                ->and($provider['configured'])->toBeFalse();
        }

        // The add list is what the modal offers, so it must not repeat a name.
        $names = array_column($section['available'], 'name');
        expect($names)->toBe(array_values(array_unique($names)));
    }
});

test('every provider option carries the category the add modal filters on', function () {
    $this->withoutVite();

    $response = $this->actingAs(createApiSettingsUserWithPermissions(['API-manage_api']))
        ->get(route('api.manager'))
        ->assertOk();

    // Without data-category on the options and data-add-provider on the button,
    // the modal cannot narrow the list and shows the whole catalog.
    $response->assertSee('data-add-provider="transcriber"', false)
        ->assertSee('data-add-provider="text_fixer"', false);

    $optionCount = substr_count($response->getContent(), 'data-configured=');
    $categoryCount = substr_count($response->getContent(), 'data-category=');

    expect($optionCount)->toBeGreaterThan(0)
        ->and($categoryCount)->toBe($optionCount);
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
