<?php

use App\Http\Controllers\Api\TranscriptionController as ApiTranscriptionController;
use App\Jobs\ProcessAsyncTranscriptionJob;
use App\Models\ApiTranscriptionJob;
use App\Models\TranscriptionProviderSetting;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Services\WebAudioChunkerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

test('web upload queues the local async transcribe api job and finalizes from status polling', function () {
    Queue::fake();

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Local async transcript',
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::response([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => 'Local async upload transcript.',
                        'words' => [],
                    ]],
                ]],
            ],
        ]),
    ]);

    $upload = $this->actingAs($user)
        ->postJson(route('workspace.upload', $project), [
            'audio' => UploadedFile::fake()->createWithContent('clip.wav', wavContent(2)),
            'server_chunk' => true,
        ])
        ->assertAccepted()
        ->assertJsonPath('transcript.status', 'queued');

    $transcriptId = $upload->json('transcript.id');
    $apiJob = ApiTranscriptionJob::query()->firstOrFail();

    Queue::assertPushed(ProcessAsyncTranscriptionJob::class, fn (ProcessAsyncTranscriptionJob $job): bool => $job->transcriptionJobId === $apiJob->id);

    expect($apiJob->request_payload['mode'])->toBe('queue')
        ->and($apiJob->request_payload['clips'])->toHaveCount(1)
        ->and($apiJob->request_payload['clips'][0]['audio_path'])->not->toBeEmpty()
        ->and($project->transcripts()->whereKey($transcriptId)->firstOrFail()->status)->toBe('queued');

    (new ProcessAsyncTranscriptionJob($apiJob->id))->handle(app(ApiTranscriptionController::class));

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.duration_seconds', 2)
        ->assertJsonPath('project.transcripts.0.raw_text', 'Local async upload transcript.')
        ->assertJsonPath('project.transcripts.0.sections.0.text', 'Local async upload transcript.');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), config('services.deepgram.listen_url')));
});

test('server chunking does not create a tiny trailing audio clip', function () {
    $chunker = app(WebAudioChunkerService::class);
    $prepared = $chunker->clipsFromUpload(
        UploadedFile::fake()->createWithContent('near-minute.wav', wavContent(60.5)),
        0,
    );

    try {
        expect($prepared['clips'])->toHaveCount(1)
            ->and($prepared['clips'][0]['clip_start_ms'])->toBe(0)
            ->and($prepared['clips'][0]['clip_end_ms'])->toBeGreaterThan(60000);
    } finally {
        $chunker->cleanup($prepared['cleanup']);
    }
});

test('web upload completes when transcription provider returns no speech text', function () {
    Queue::fake();

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Silent transcript',
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::response([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => '',
                        'words' => [],
                    ]],
                ]],
            ],
        ]),
    ]);

    $upload = $this->actingAs($user)
        ->postJson(route('workspace.upload', $project), [
            'audio' => UploadedFile::fake()->createWithContent('silence.wav', wavContent(2)),
            'server_chunk' => true,
        ])
        ->assertAccepted();

    $apiJob = ApiTranscriptionJob::query()->firstOrFail();

    (new ProcessAsyncTranscriptionJob($apiJob->id))->handle(app(ApiTranscriptionController::class));

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.id', $upload->json('transcript.id'))
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.raw_text', '')
        ->assertJsonPath('project.transcripts.0.sections.0.text', '');
});

function wavContent(int|float $seconds): string
{
    $sampleRate = 16000;
    $channels = 1;
    $bitsPerSample = 16;
    $sampleCount = (int) round($sampleRate * $seconds);
    $data = '';

    for ($index = 0; $index < $sampleCount; $index++) {
        $sample = (int) round(sin(2 * M_PI * 440 * ($index / $sampleRate)) * 8000);
        $data .= pack('v', $sample < 0 ? $sample + 65536 : $sample);
    }

    $byteRate = $sampleRate * $channels * intdiv($bitsPerSample, 8);
    $blockAlign = $channels * intdiv($bitsPerSample, 8);

    return 'RIFF'
        .pack('V', 36 + strlen($data))
        .'WAVEfmt '
        .pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
        .'data'
        .pack('V', strlen($data))
        .$data;
}
