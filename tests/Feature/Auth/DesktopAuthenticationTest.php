<?php

use App\Models\User;

test('desktop authentication uses the same case insensitive credentials as Fortify', function () {
    $user = User::factory()->create([
        'email' => 'Desktop.User@Example.com',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'desktop.user@example.com',
        'password' => 'password',
        'device_name' => 'JERVA Transcriber',
    ])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'Desktop.User@Example.com')
        ->assertJsonPath('token_type', 'Bearer');
});

test('Fortify browser authentication uses the shared credential action', function () {
    $user = User::factory()->create([
        'email' => 'Browser.User@Example.com',
    ]);

    $this->post(route('login.store'), [
        'email' => 'browser.user@example.com',
        'password' => 'password',
    ])->assertRedirect(route('workspace.index', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('desktop authentication rejects invalid credentials without creating a token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
        'device_name' => 'JERVA Transcriber',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($user->tokens()->count())->toBe(0);
});
