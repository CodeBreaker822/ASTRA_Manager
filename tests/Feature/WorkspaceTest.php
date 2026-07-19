<?php

use App\Models\TranscriptProject;
use App\Models\User;

test('workspace requires authentication', function () {
    $this->get(route('workspace.index'))
        ->assertRedirect(route('login'));
});

test('verified users can view the workspace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspace.index'))
        ->assertOk();
});

test('users can create rename and delete their transcript projects', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspace.store'), ['title' => 'Client intake call'])
        ->assertRedirect();

    $project = TranscriptProject::query()->where('user_id', $user->id)->firstOrFail();

    expect($project->title)->toBe('Client intake call');

    $this->actingAs($user)
        ->put(route('workspace.update', $project), ['title' => 'Updated call'])
        ->assertRedirect();

    expect($project->refresh()->title)->toBe('Updated call');

    $this->actingAs($user)
        ->delete(route('workspace.destroy', $project))
        ->assertRedirect(route('workspace.index'));

    expect(TranscriptProject::query()->whereKey($project->id)->exists())->toBeFalse();
});

test('users cannot manage another users transcript project', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $owner->id,
        'title' => 'Private transcript',
    ]);

    $this->actingAs($other)
        ->get(route('workspace.show', $project))
        ->assertNotFound();

    $this->actingAs($other)
        ->put(route('workspace.update', $project), ['title' => 'Nope'])
        ->assertNotFound();

    $this->actingAs($other)
        ->delete(route('workspace.destroy', $project))
        ->assertNotFound();
});
