<?php

use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('workspace.index', absolute: false));
});

test('desktop login returns a token license and account credits', function () {
    $user = User::factory()->create([
        'wallet_balance' => 12.50,
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'JERVA Transcriber',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('license.active', true)
        ->assertJsonPath('account.credits.wallet_balance', 12.5)
        ->assertJsonPath('account.credits.wallet_balance_cents', 1250)
        ->assertJsonPath('account.credits.label', '$12.50')
        ->assertJsonPath('account.entitlements.plan.key', 'payg');

    expect($response->json('access_token'))->not->toBeEmpty()
        ->and($response->json('license.key'))->toStartWith('is_license_');
});

test('configured admins are redirected to dashboard after login', function () {
    config([
        'admin.email' => 'work.jgnc@gmail.com',
        'admin.access' => true,
    ]);

    $user = User::factory()->create(['email' => 'work.jgnc@gmail.com']);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users with any assigned permission are redirected to dashboard after login', function () {
    $user = createAuthUserWithPermissions(['certificates.view']);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('configured admin email still redirects to workspace when admin access is disabled', function () {
    config([
        'admin.email' => 'work.jgnc@gmail.com',
        'admin.access' => false,
    ]);

    $user = User::factory()->create(['email' => 'work.jgnc@gmail.com']);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('workspace.index', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

function createAuthUserWithPermissions(array $permissions): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_AUTH_DASHBOARD',
        'position_name' => 'Test Auth Dashboard',
        'assigned_office' => 'web',
        'category' => 'cms',
        'description' => 'Test auth dashboard position',
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
