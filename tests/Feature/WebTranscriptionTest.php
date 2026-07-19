<?php

use App\Jobs\ProcessWebTranscriptJob;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('users can queue an uploaded transcript', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Upload test',
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.upload', $project), [
            'audio' => UploadedFile::fake()->create('meeting.wav', 64, 'audio/wav'),
            'duration_seconds' => 30,
        ])
        ->assertAccepted()
        ->assertJsonPath('transcript.status', 'queued');

    $transcript = Transcript::query()->where('project_id', $project->id)->firstOrFail();

    expect($transcript->source)->toBe('upload')
        ->and($transcript->status)->toBe('queued')
        ->and($transcript->duration_seconds)->toBe(30);

    Storage::disk('local')->assertExists($transcript->audio_path);
    Queue::assertPushed(ProcessWebTranscriptJob::class);
});

test('free users cannot queue live chunks', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Live test',
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.chunk', $project), [
            'audio' => UploadedFile::fake()->create('clip.webm', 64, 'audio/webm'),
            'duration_seconds' => 10,
        ])
        ->assertPaymentRequired()
        ->assertJsonPath('upgrade', true);

    Queue::assertNothingPushed();
});

test('pro users can queue live chunks', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create(['plan' => 'pro']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Live test',
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.chunk', $project), [
            'audio' => UploadedFile::fake()->create('clip.webm', 64, 'audio/webm'),
            'duration_seconds' => 10,
            'clip_index' => 1,
        ])
        ->assertAccepted()
        ->assertJsonPath('transcript.source', 'live');

    Queue::assertPushed(ProcessWebTranscriptJob::class);
});

test('workspace status returns persisted transcripts and usage', function () {
    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Status test',
    ]);
    $project->transcripts()->create([
        'source' => 'upload',
        'status' => 'completed',
        'raw_text' => 'Hello world.',
        'processing_log' => [['status' => 'completed', 'message' => 'Done', 'created_at' => now()->toISOString()]],
    ]);

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.title', 'Status test')
        ->assertJsonPath('project.transcripts.0.raw_text', 'Hello world.')
        ->assertJsonPath('entitlements.plan.key', 'free');
});

test('users can export a txt transcript', function () {
    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Export test',
    ]);
    $transcript = $project->transcripts()->create([
        'source' => 'upload',
        'status' => 'completed',
        'raw_text' => 'Export me.',
        'processing_log' => [],
    ]);

    $this->actingAs($user)
        ->get(route('workspace.transcripts.export', [
            'project' => $project,
            'transcript' => $transcript,
            'format' => 'txt',
            'source' => 'raw',
        ]))
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');
});
