<?php

use App\Jobs\ProcessWebPolishJob;
use App\Jobs\ProcessWebSummarizeJob;
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

test('users can queue a batched uploaded transcript', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Batch upload test',
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.upload', $project), [
            'audio' => [
                UploadedFile::fake()->create('meeting-1.wav', 64, 'audio/wav'),
                UploadedFile::fake()->create('meeting-2.wav', 64, 'audio/wav'),
            ],
            'clip_index' => [0, 1],
            'clip_start_ms' => [0, 60000],
            'clip_end_ms' => [60000, 120000],
        ])
        ->assertAccepted()
        ->assertJsonPath('transcript.status', 'queued');

    $transcript = Transcript::query()->where('project_id', $project->id)->firstOrFail();
    $clips = $transcript->processing_log[0]['context']['clips'] ?? [];

    expect($clips)->toHaveCount(2)
        ->and($transcript->duration_seconds)->toBe(120);

    Storage::disk('local')->assertExists($clips[0]['path']);
    Storage::disk('local')->assertExists($clips[1]['path']);
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

test('pro users can queue transcript polishing', function () {
    Queue::fake();

    $user = User::factory()->create(['plan' => 'pro']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Polish test',
    ]);
    $transcript = $project->transcripts()->create([
        'source' => 'upload',
        'status' => 'completed',
        'raw_text' => 'hello world',
        'processing_log' => [],
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.transcripts.polish', [$project, $transcript]), [
            'preset' => 'grammar',
        ])
        ->assertAccepted()
        ->assertJsonPath('transcript.polish_status', 'processing');

    expect($transcript->refresh()->status)->toBe('completed')
        ->and($transcript->polish_status)->toBe('processing');

    Queue::assertPushed(ProcessWebPolishJob::class);
});

test('polishing requires a raw transcript', function () {
    Queue::fake();

    $user = User::factory()->create(['plan' => 'pro']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Empty polish test',
    ]);
    $transcript = $project->transcripts()->create([
        'source' => 'upload',
        'status' => 'completed',
        'processing_log' => [],
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.transcripts.polish', [$project, $transcript]), [
            'preset' => 'grammar',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No raw transcript is ready to polish yet.');

    Queue::assertNotPushed(ProcessWebPolishJob::class);
});

test('pro users can queue transcript summaries', function () {
    Queue::fake();

    $user = User::factory()->create(['plan' => 'pro']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Summary test',
    ]);
    $transcript = $project->transcripts()->create([
        'source' => 'upload',
        'status' => 'completed',
        'raw_text' => 'hello world',
        'processing_log' => [],
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.transcripts.summarize', [$project, $transcript]), [
            'source' => 'raw',
        ])
        ->assertAccepted()
        ->assertJsonPath('transcript.summary_status', 'processing');

    expect($transcript->refresh()->status)->toBe('completed')
        ->and($transcript->summary_status)->toBe('processing');

    Queue::assertPushed(ProcessWebSummarizeJob::class);
});

test('summaries require a raw transcript', function () {
    Queue::fake();

    $user = User::factory()->create(['plan' => 'pro']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Empty summary test',
    ]);
    $transcript = $project->transcripts()->create([
        'source' => 'upload',
        'status' => 'completed',
        'processing_log' => [],
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace.transcripts.summarize', [$project, $transcript]), [
            'source' => 'raw',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The transcript could not be summarized.');

    Queue::assertNotPushed(ProcessWebSummarizeJob::class);
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
