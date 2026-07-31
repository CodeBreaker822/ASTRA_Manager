<?php

use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Services\WebTranscriptProcessor;

test('polish uses the currently displayed polished transcript as its source', function () {
    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Revision source',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'raw_text' => 'Raw transcript.',
        'cleaned_text' => 'First polished transcript.',
    ]);
    $processor = app(WebTranscriptProcessor::class);
    $sourceText = new ReflectionMethod($processor, 'sourceText');

    expect($sourceText->invoke($processor, $transcript))
        ->toBe('First polished transcript.');
});

test('undo restores each polished revision and finally returns to raw', function () {
    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Undo revisions',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'raw_text' => 'Raw transcript.',
        'cleaned_text' => 'Second polished transcript.',
        'polish_history' => [null, 'First polished transcript.'],
        'polish_status' => 'complete',
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.transcripts.polish.undo', [$project, $transcript]))
        ->assertOk()
        ->assertJsonPath('transcript.cleaned_text', 'First polished transcript.')
        ->assertJsonPath('transcript.can_undo_polish', true);

    expect($transcript->refresh()->polish_history)->toBe([null]);

    $this->actingAs($user)
        ->postJson(route('workspace.transcripts.polish.undo', [$project, $transcript]))
        ->assertOk()
        ->assertJsonPath('transcript.cleaned_text', null)
        ->assertJsonPath('transcript.can_undo_polish', false);

    expect($transcript->refresh()->polish_history)
        ->toBe([])
        ->and($transcript->polish_status)->toBe('idle');
});
