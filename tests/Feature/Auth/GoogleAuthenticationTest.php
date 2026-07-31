<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
    ]);
});

test('google sign in matches an existing email without regard to case', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'Existing.User@Example.com',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'existing.user@example.com',
        'email_verified' => true,
    ]));

    $this->get('/auth/google/callback')
        ->assertRedirect(route('workspace.index', absolute: false));

    $this->assertAuthenticatedAs($user);

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('google sign in creates and verifies an account for a new email', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'new.user@example.com',
        'email_verified' => true,
    ]));

    $this->get('/auth/google/callback')
        ->assertRedirect(route('workspace.index', absolute: false));

    $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('google sign in starts on the configured callback hostname', function () {
    $request = Request::create('http://localhost/auth/google/redirect');
    $response = app(\App\Http\Controllers\Auth\GoogleAuthController::class)
        ->redirect($request);

    expect($response->getTargetUrl())
        ->toBe('http://127.0.0.1:8000/auth/google/redirect');
});

test('passkey routes are disabled', function () {
    expect(Route::has('passkey.login'))
        ->toBeFalse()
        ->and(Route::has('passkey.store'))->toBeFalse()
        ->and(Route::has('well-known.passkeys'))->toBeFalse();
});
