<?php

use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Services\Transcription\WebApiTranscriptionClient;
use App\Services\Transcription\WebTranscriptProcessor;
use App\Services\Web\TranscriptPayloadPresenter;
use App\Support\WorkspaceView;

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

test('polishing keeps one row per minute instead of collapsing the transcript', function () {
    $user = User::factory()->create(['plan' => 'payg', 'wallet_balance' => 50]);
    $project = TranscriptProject::query()->create(['user_id' => $user->id, 'title' => 'Per minute']);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'duration_seconds' => 180,
        'raw_text' => 'minute one raw. minute two raw. minute three raw.',
        'polish_status' => 'processing',
    ]);

    foreach ([0, 1, 2] as $index) {
        $transcript->sections()->create([
            'position' => $index,
            'text' => 'minute '.($index + 1).' raw.',
            'started_at_ms' => $index * 60_000,
            'ended_at_ms' => ($index + 1) * 60_000,
            'speaker_timestamps' => [],
        ]);
    }

    $sent = [];
    $client = Mockery::mock(WebApiTranscriptionClient::class)->makePartial();
    $client->shouldReceive('polish')->andReturnUsing(
        function (User $u, string $text, array $chunks, string $instruction, string $task = 'polish') use (&$sent): array {
            $sent = $chunks;

            return ['chunks' => array_map(fn (array $chunk): array => [
                'audio_chunk_id' => $chunk['audio_chunk_id'],
                'text' => 'POLISHED '.$chunk['text'],
                'timestamps' => [],
            ], $chunks)];
        }
    );
    app()->instance(WebApiTranscriptionClient::class, $client);

    app(WebTranscriptProcessor::class)->polish($transcript, 'Fix grammar');

    // One chunk per section, each carrying its own range.
    expect($sent)->toHaveCount(3)
        ->and($sent[0]['range_label'])->toBe('00:00-01:00')
        ->and($sent[2]['range_label'])->toBe('02:00-03:00');

    $rows = WorkspaceView::transcriptRows([
        'transcripts' => [app(TranscriptPayloadPresenter::class)->present($transcript->refresh())],
    ]);

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['range' => '00:00-01:00', 'text' => 'POLISHED minute 1 raw.'])
        ->and($rows[2])->toMatchArray(['range' => '02:00-03:00', 'text' => 'POLISHED minute 3 raw.']);

    // Undo has to put the sections back, not just the joined text.
    app(WebTranscriptProcessor::class)->undoPolish($transcript);

    $undone = WorkspaceView::transcriptRows([
        'transcripts' => [app(TranscriptPayloadPresenter::class)->present($transcript->refresh())],
    ]);

    expect($undone)->toHaveCount(3)
        ->and($undone[0]['text'])->toBe('minute 1 raw.')
        ->and($transcript->refresh()->cleaned_text)->toBeNull();
});
