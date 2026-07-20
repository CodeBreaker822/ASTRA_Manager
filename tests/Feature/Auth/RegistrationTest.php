<?php

use App\Models\API;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('workspace.index', absolute: false));

    $license = API::query()->whereNotNull('user_id')->firstOrFail();

    expect($license->app_name)->toStartWith('web-user-')
        ->and($license->app_token)->toStartWith('is_license_')
        ->and($license->can_post)->toBeTrue()
        ->and($license->can_get)->toBeTrue()
        ->and($license->is_active)->toBeTrue();
});
