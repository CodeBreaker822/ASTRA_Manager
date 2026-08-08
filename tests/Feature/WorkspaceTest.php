<?php

use App\Jobs\ProcessApiTranscriptionJob;
use App\Models\ApiTranscriptionJob;
use App\Models\Transcript;
use App\Models\TranscriptionProviderSetting;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Services\Transcription\TranscriptExportService;
use App\Services\Transcription\WebAudioChunkerService;
use App\Support\SummaryMarkdown;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;

test('workspace requires authentication', function () {
    $this->get(route('workspace.index'))
        ->assertRedirect(route('login'));
});

test('verified users can view the workspace', function () {
    $user = User::factory()->create(['plan' => 'free']);

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
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

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
        'metadata' => deepgramRuntimeMetadata(),
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

    expect($apiJob->request_payload['mode'])->toBe('queue_worker')
        ->and($apiJob->request_payload['clips'])->toHaveCount(1)
        ->and($apiJob->request_payload['clips'][0]['audio_path'])->not->toBeEmpty()
        ->and($project->transcripts()->whereKey($transcriptId)->firstOrFail()->status)->toBe('queued');

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.duration_seconds', 2)
        ->assertJsonPath('project.transcripts.0.raw_text', 'Local async upload transcript.')
        ->assertJsonPath('project.transcripts.0.sections.0.text', 'Local async upload transcript.');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), config('services.deepgram.listen_url')));
});

test('web upload hands transcription to a queue worker instead of the status poll', function () {
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

    Queue::fake();

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Queue worker transcript',
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => deepgramRuntimeMetadata(),
    ]);

    Http::fake();

    $this->actingAs($user)
        ->postJson(route('workspace.upload', $project), [
            'audio' => UploadedFile::fake()->createWithContent('clip.wav', wavContent(2)),
            'server_chunk' => true,
        ])
        ->assertAccepted();

    $apiJob = ApiTranscriptionJob::query()->firstOrFail();

    Queue::assertPushed(
        ProcessApiTranscriptionJob::class,
        fn (ProcessApiTranscriptionJob $job): bool => $job->transcriptionJobId === $apiJob->id,
    );

    // The poll reports progress; it must no longer be the thing that does the
    // transcribing, which is what used to pin the upload dock at 50%.
    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.status', 'queued');

    expect($apiJob->fresh()->status)->toBe('queued');
    Http::assertNothingSent();
});

test('web upload submits server audio chunks in batches of twenty and exposes each completed batch', function () {
    $preparedPaths = array_map(
        fn (int $index): string => tap(tempnam(sys_get_temp_dir(), 'workspace-api-clip-'.$index.'-'), fn (string $path) => file_put_contents($path, 'prepared clip '.$index)),
        range(0, 20),
    );

    $this->mock(WebAudioChunkerService::class, function ($mock) use ($preparedPaths): void {
        $mock->shouldReceive('clipsFromUpload')->once()->andReturn([
            'clips' => array_map(
                fn (string $path, int $index): array => [
                    'audio' => new UploadedFile($path, 'clip-'.$index.'.wav', 'audio/wav', null, true),
                    'clip_index' => $index,
                    'clip_start_ms' => $index * 2000,
                    'clip_end_ms' => ($index + 1) * 2000,
                    'language_code' => null,
                ],
                $preparedPaths,
                array_keys($preparedPaths),
            ),
            'cleanup' => null,
        ]);
        $mock->shouldReceive('cleanup')->zeroOrMoreTimes();
    });

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Multi-job web transcript',
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => deepgramRuntimeMetadata(),
    ]);

    $providerResponses = Http::sequence();

    foreach (range(1, 21) as $position) {
        $providerResponses->push([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => 'Clip '.$position.'.',
                        'words' => [],
                    ]],
                ]],
            ],
        ]);
    }

    Http::fake([
        config('services.deepgram.listen_url').'*' => $providerResponses,
    ]);

    try {
        $upload = $this->actingAs($user)
            ->postJson(route('workspace.upload', $project), [
                'audio' => UploadedFile::fake()->createWithContent('source.wav', 'source audio'),
                'server_chunk' => true,
            ])
            ->assertAccepted()
            ->assertJsonPath('transcript.status', 'queued')
            ->assertJsonPath('transcript.transcription_progress.processed_clips', 0)
            ->assertJsonPath('transcript.transcription_progress.total_clips', 21);

        $apiJobs = ApiTranscriptionJob::query()->orderBy('created_at')->get();

        expect($apiJobs)->toHaveCount(2)
            ->and($apiJobs[0]->request_payload['clips'])->toHaveCount(20)
            ->and($apiJobs[1]->request_payload['clips'])->toHaveCount(1);

        $this->actingAs($user)
            ->getJson(route('workspace.status', $project))
            ->assertOk()
            ->assertJsonPath('project.transcripts.0.status', 'processing')
            ->assertJsonPath('project.transcripts.0.transcription_progress.processed_clips', 20)
            ->assertJsonPath('project.transcripts.0.transcription_progress.total_clips', 21)
            ->assertJsonPath('project.transcripts.0.raw_text', collect(range(1, 20))->map(fn (int $position): string => 'Clip '.$position.'.')->implode("\n\n"))
            ->assertJsonPath('project.transcripts.0.sections.0.text', 'Clip 1.')
            ->assertJsonPath('project.transcripts.0.sections.19.text', 'Clip 20.')
            ->assertJsonCount(20, 'project.transcripts.0.sections');

        $this->actingAs($user)
            ->getJson(route('workspace.status', $project))
            ->assertOk()
            ->assertJsonPath('project.transcripts.0.status', 'completed')
            ->assertJsonPath('project.transcripts.0.transcription_progress.processed_clips', 21)
            ->assertJsonPath('project.transcripts.0.transcription_progress.percentage', 100)
            ->assertJsonPath('project.transcripts.0.raw_text', collect(range(1, 21))->map(fn (int $position): string => 'Clip '.$position.'.')->implode("\n\n"))
            ->assertJsonPath('project.transcripts.0.sections.20.text', 'Clip 21.')
            ->assertJsonCount(21, 'project.transcripts.0.sections');

        expect($upload->json('transcript.id'))->not->toBeNull();
        Http::assertSentCount(21);
    } finally {
        foreach ($preparedPaths as $path) {
            @unlink($path);
        }
    }
});

test('completing the same upload session twice creates only one transcript', function () {
    $preparedClipPath = tempnam(sys_get_temp_dir(), 'workspace-prepared-clip-');
    file_put_contents($preparedClipPath, 'prepared clip bytes');

    $this->mock(WebAudioChunkerService::class, function ($mock) use ($preparedClipPath): void {
        $mock->shouldReceive('clipsFromUpload')->once()->andReturn([
            'clips' => [[
                'audio' => new UploadedFile($preparedClipPath, 'prepared.wav', 'audio/wav', null, true),
                'clip_index' => 0,
                'clip_start_ms' => 0,
                'clip_end_ms' => 2000,
                'language_code' => null,
            ]],
            'cleanup' => null,
        ]);
        $mock->shouldReceive('cleanup')->zeroOrMoreTimes();
    });

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Duplicate completion guard',
    ]);
    $contents = 'duplicate completion source bytes';
    $chunkPath = tempnam(sys_get_temp_dir(), 'workspace-audio-part-');
    file_put_contents($chunkPath, $contents);
    $uploadId = 'workspace-upload-'.bin2hex(random_bytes(4));

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => deepgramRuntimeMetadata(),
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::response([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => 'Duplicate completion transcript.',
                        'words' => [],
                    ]],
                ]],
            ],
        ]),
    ]);

    try {
        $this->actingAs($user)
            ->postJson(route('workspace.upload.chunk', $project), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'total_size' => strlen($contents),
                'filename' => 'duplicate.wav',
                'mime_type' => 'audio/wav',
                'chunk_hash' => hash_file('sha256', $chunkPath),
                'chunk' => new UploadedFile($chunkPath, 'duplicate.part0', 'application/octet-stream', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('complete', true);

        $this->actingAs($user)
            ->postJson(route('workspace.upload.complete', $project), [
                'upload_id' => $uploadId,
            ])
            ->assertAccepted()
            ->assertJsonPath('transcript.status', 'queued');

        // The frontend treats this exact rejection as proof that the server
        // already consumed the source, and recovers by locating the transcript
        // the first request produced instead of uploading again.
        $this->actingAs($user)
            ->postJson(route('workspace.upload.complete', $project), [
                'upload_id' => $uploadId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The upload session was not found.');
    } finally {
        @unlink($chunkPath);
        @unlink($preparedClipPath);
    }

    expect(Transcript::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('chunked web upload rebuilds audio and removes stored audio after completion', function () {
    $preparedClipPath = tempnam(sys_get_temp_dir(), 'workspace-prepared-clip-');
    file_put_contents($preparedClipPath, 'prepared clip bytes');

    $this->mock(WebAudioChunkerService::class, function ($mock) use ($preparedClipPath): void {
        $mock->shouldReceive('clipsFromUpload')->once()->andReturn([
            'clips' => [[
                'audio' => new UploadedFile($preparedClipPath, 'prepared.wav', 'audio/wav', null, true),
                'clip_index' => 0,
                'clip_start_ms' => 0,
                'clip_end_ms' => 2000,
                'language_code' => null,
            ]],
            'cleanup' => null,
        ]);
        $mock->shouldReceive('cleanup')->zeroOrMoreTimes();
    });

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Chunked upload transcript',
    ]);
    $contents = 'rebuilt source bytes';
    $first = substr($contents, 0, 8);
    $second = substr($contents, 8);
    $firstPath = tempnam(sys_get_temp_dir(), 'workspace-audio-part-a-');
    $secondPath = tempnam(sys_get_temp_dir(), 'workspace-audio-part-b-');
    file_put_contents($firstPath, $first);
    file_put_contents($secondPath, $second);
    $uploadId = 'workspace-upload-'.bin2hex(random_bytes(4));

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => deepgramRuntimeMetadata(),
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::response([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => 'Chunked upload transcript.',
                        'words' => [],
                    ]],
                ]],
            ],
        ]),
    ]);

    try {
        $this->actingAs($user)
            ->postJson(route('workspace.upload.chunk', $project), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 2,
                'total_size' => strlen($contents),
                'filename' => 'chunked.wav',
                'mime_type' => 'audio/wav',
                'chunk_hash' => hash_file('sha256', $firstPath),
                'chunk' => new UploadedFile($firstPath, 'chunked.part0', 'application/octet-stream', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('received_chunks', 1);

        $this->actingAs($user)
            ->postJson(route('workspace.upload.chunk', $project), [
                'upload_id' => $uploadId,
                'chunk_index' => 1,
                'total_chunks' => 2,
                'total_size' => strlen($contents),
                'filename' => 'chunked.wav',
                'mime_type' => 'audio/wav',
                'chunk_hash' => hash_file('sha256', $secondPath),
                'chunk' => new UploadedFile($secondPath, 'chunked.part1', 'application/octet-stream', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('complete', true);

        $upload = $this->actingAs($user)
            ->postJson(route('workspace.upload.complete', $project), [
                'upload_id' => $uploadId,
            ])
            ->assertAccepted()
            ->assertJsonPath('transcript.status', 'queued');
    } finally {
        @unlink($firstPath);
        @unlink($secondPath);
        @unlink($preparedClipPath);
    }

    expect(is_dir(storage_path('app/private/chunked-uploads/workspace-audio/user-'.$user->id.'-project-'.$project->id.'/'.$uploadId)))->toBeFalse();

    $transcript = Transcript::query()->findOrFail($upload->json('transcript.id'));
    $storedAudioPath = (string) $transcript->audio_path;
    Storage::disk('local')->assertExists($storedAudioPath);

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.raw_text', 'Chunked upload transcript.');

    Storage::disk('local')->assertMissing($storedAudioPath);
});

test('chunked web upload processes real audio with ffmpeg when available', function () {
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

    $user = User::factory()->create(['plan' => 'payg']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Chunked upload transcript',
    ]);
    $contents = wavContent(2);
    $first = substr($contents, 0, 128);
    $second = substr($contents, 128);
    $firstPath = tempnam(sys_get_temp_dir(), 'workspace-audio-part-a-');
    $secondPath = tempnam(sys_get_temp_dir(), 'workspace-audio-part-b-');
    file_put_contents($firstPath, $first);
    file_put_contents($secondPath, $second);
    $uploadId = 'workspace-upload-'.bin2hex(random_bytes(4));

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => deepgramRuntimeMetadata(),
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::response([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'transcript' => 'Chunked upload transcript.',
                        'words' => [],
                    ]],
                ]],
            ],
        ]),
    ]);

    try {
        $this->actingAs($user)
            ->postJson(route('workspace.upload.chunk', $project), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 2,
                'total_size' => strlen($contents),
                'filename' => 'chunked.wav',
                'mime_type' => 'audio/wav',
                'chunk_hash' => hash_file('sha256', $firstPath),
                'chunk' => new UploadedFile($firstPath, 'chunked.part0', 'application/octet-stream', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('received_chunks', 1);

        $this->actingAs($user)
            ->postJson(route('workspace.upload.chunk', $project), [
                'upload_id' => $uploadId,
                'chunk_index' => 1,
                'total_chunks' => 2,
                'total_size' => strlen($contents),
                'filename' => 'chunked.wav',
                'mime_type' => 'audio/wav',
                'chunk_hash' => hash_file('sha256', $secondPath),
                'chunk' => new UploadedFile($secondPath, 'chunked.part1', 'application/octet-stream', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('complete', true);

        $upload = $this->actingAs($user)
            ->postJson(route('workspace.upload.complete', $project), [
                'upload_id' => $uploadId,
            ])
            ->assertAccepted()
            ->assertJsonPath('transcript.status', 'queued');
    } finally {
        @unlink($firstPath);
        @unlink($secondPath);
    }

    expect(is_dir(storage_path('app/private/chunked-uploads/workspace-audio/user-'.$user->id.'-project-'.$project->id.'/'.$uploadId)))->toBeFalse();

    $transcript = Transcript::query()->findOrFail($upload->json('transcript.id'));
    $storedAudioPath = (string) $transcript->audio_path;
    Storage::disk('local')->assertExists($storedAudioPath);

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.raw_text', 'Chunked upload transcript.');

    Storage::disk('local')->assertMissing($storedAudioPath);
});

test('server chunking does not create a tiny trailing audio clip', function () {
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

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
    $ffmpeg = availableFfmpegBinary();

    if ($ffmpeg === null) {
        $this->markTestSkipped('FFmpeg is not installed on this machine.');
    }

    config(['services.ffmpeg.binary' => $ffmpeg]);

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
        'metadata' => deepgramRuntimeMetadata(),
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

    // The transient status depends on the queue driver, so assert the handoff
    // itself rather than whether a worker has already gotten to it.
    expect(ApiTranscriptionJob::query()->firstOrFail()->request_payload['mode'])->toBe('queue_worker');

    $this->actingAs($user)
        ->getJson(route('workspace.status', $project))
        ->assertOk()
        ->assertJsonPath('project.transcripts.0.id', $upload->json('transcript.id'))
        ->assertJsonPath('project.transcripts.0.status', 'completed')
        ->assertJsonPath('project.transcripts.0.raw_text', '')
        ->assertJsonPath('project.transcripts.0.sections.0.text', '');
});

test('workspace summary modal follows the jerva summary design surface', function () {
    // Summary markdown is rendered server-side, so assert the produced markup
    // rather than the blade source.
    $rendered = SummaryMarkdown::render(
        "# Heading\n\n- First **bold** point\n- Second point\n\nA closing paragraph.",
    );

    expect($rendered)
        ->toContain('mt-5 first:mt-0 text-sm font-semibold uppercase text-blue-700')
        ->toContain('my-3 ml-5 list-disc space-y-2')
        ->toContain('my-3 first:mt-0 last:mb-0')
        ->toContain('font-semibold text-black');

    expect(SummaryMarkdown::render(''))
        ->toContain('text-blue-900')
        ->toContain('No summary has been created for this project.');

    $modals = File::get(resource_path('views/workspace/partials/action-modals.blade.php'));

    expect($modals)
        ->toContain('SummaryMarkdown::render')
        ->toContain('data-summary-export-format')
        ->toContain('No summary has been created for this project.')
        // The summary modal offers formats only; no source picker or icons.
        ->not->toContain('summary_source');

    // The overlay tint is a shared token now, so assert it where it lives.
    expect(config('ui.workspace.modal.shell'))->toContain('bg-blue-950/30');

    $exportModal = str($modals)->after('{{-- Export --}}')->before('{{-- Processing log --}}')->toString();

    expect($exportModal)->not->toContain('summary_source');
});

test('transcript exports follow the jerva desktop document layout', function () {
    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Design review',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'duration_seconds' => 60,
        'raw_text' => 'Welcome to the review. Let us begin.',
    ]);
    $transcript->sections()->create([
        'position' => 0,
        'text' => 'Welcome to the review. Let us begin.',
        'started_at_ms' => 0,
        'ended_at_ms' => 60000,
        'speaker_timestamps' => [
            ['speaker_id' => 'speaker_1', 'text' => 'Welcome to the review.'],
            ['speaker_id' => 'speaker_2', 'text' => 'Let us begin.'],
        ],
    ]);

    $exports = app(TranscriptExportService::class);
    $files = [];

    try {
        $txt = $exports->export($transcript, 'txt', 'raw');
        $files[] = $txt['path'];

        expect($txt['name'])->toBe('design-review-raw-transcription.txt')
            ->and(File::get($txt['path']))
            ->toContain('00:00-01:00')
            ->toContain('Speaker 1: Welcome to the review.')
            ->toContain('Speaker 2: Let us begin.');

        if (class_exists(ZipArchive::class)) {
            $docx = $exports->export($transcript, 'docx', 'raw');
            $files[] = $docx['path'];
            $archive = new ZipArchive;
            $archive->open($docx['path']);
            $documentXml = $archive->getFromName('word/document.xml');
            $archive->close();

            expect($documentXml)
                ->toContain('Design review - Raw Transcript')
                ->toContain('JERVA Transcriber')
                ->toContain('Speaker 1:');
        }

        if (extension_loaded('zip') && extension_loaded('gd')) {
            $xlsx = $exports->export($transcript, 'xlsx', 'raw');
            $files[] = $xlsx['path'];
            $workbook = IOFactory::load($xlsx['path']);
            $sheet = $workbook->getActiveSheet();

            expect($xlsx['name'])->toBe('design-review-raw-transcription.xlsx')
                ->and($sheet->getCell('A1')->getValue())->toBe('Design review - Raw Transcript')
                ->and($sheet->getCell('A2')->getValue())->toBe('Generated by JERVA Transcriber')
                ->and($sheet->getCell('A4')->getValue())->toBe('#')
                ->and($sheet->getCell('B4')->getValue())->toBe('Time Range')
                ->and($sheet->getCell('C4')->getValue())->toBe('Speakers')
                ->and($sheet->getCell('D4')->getValue())->toBe('Transcript')
                ->and($sheet->getCell('C5')->getValue())->toBe('Speaker 1, Speaker 2')
                ->and($sheet->getStyle('A4')->getFill()->getStartColor()->getRGB())->toBe('0F172A');

            $workbook->disconnectWorksheets();
        }
    } finally {
        File::delete($files);
    }
});

test('summary exports use the current transcript and jerva summary layout', function () {
    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Council meeting',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'duration_seconds' => 120,
        'raw_text' => 'Raw meeting transcript.',
        'cleaned_text' => 'Cleaned meeting transcript.',
        'summary_text' => "## Outcome\n- **Approved** the request\nNext step confirmed.",
        'summary_status' => 'complete',
    ]);

    $exports = app(TranscriptExportService::class);
    $files = [];

    try {
        $txt = $exports->export($transcript, 'txt', 'summary');
        $files[] = $txt['path'];

        expect(File::get($txt['path']))
            ->toContain('Council meeting - Summary')
            ->toContain('Project: Council meeting')
            ->toContain('Source: Current transcript')
            ->toContain('- Approved the request')
            ->toContain('Next step confirmed.');

        if (class_exists(ZipArchive::class)) {
            $docx = $exports->export($transcript, 'docx', 'summary');
            $files[] = $docx['path'];
            $archive = new ZipArchive;
            $archive->open($docx['path']);
            $documentXml = $archive->getFromName('word/document.xml');
            $archive->close();

            expect($documentXml)
                ->toContain('Council meeting - Summary')
                ->toContain('SOURCE: Current transcript')
                ->toContain('Outcome')
                ->toContain('Approved')
                ->toContain('w:val="312E81"')
                ->toContain('w:color="DDD6FE"')
                ->toContain('•');
        }

        if (extension_loaded('zip') && extension_loaded('gd')) {
            $xlsx = $exports->export($transcript, 'xlsx', 'summary');
            $files[] = $xlsx['path'];
            $workbook = IOFactory::load($xlsx['path']);
            $sheet = $workbook->getActiveSheet();
            $summaryValue = $sheet->getCell('B5')->getValue();

            expect($sheet->getCell('A1')->getValue())->toBe('Council meeting - Summary')
                ->and($sheet->getCell('A3')->getValue())->toBe('Project')
                ->and($sheet->getCell('B3')->getValue())->toBe('Council meeting')
                ->and($sheet->getCell('A4')->getValue())->toBe('Source')
                ->and($sheet->getCell('B4')->getValue())->toBe('Current transcript')
                ->and($sheet->getCell('A5')->getValue())->toBe('Summary')
                ->and($summaryValue->getPlainText())->toContain('• Approved the request')
                ->and($sheet->getStyle('A1')->getFont()->getColor()->getRGB())->toBe('7C3AED')
                ->and($sheet->getStyle('A3')->getFill()->getStartColor()->getRGB())->toBe('312E81')
                ->and($sheet->getStyle('B4')->getFill()->getStartColor()->getRGB())->toBe('F8FAFC');

            $workbook->disconnectWorksheets();
        }
    } finally {
        File::delete($files);
    }
});

test('excel exports report missing php spreadsheet extensions clearly', function () {
    if (extension_loaded('zip') && extension_loaded('gd')) {
        $this->markTestSkipped('PHP zip and gd extensions are enabled.');
    }

    $user = User::factory()->create();
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Council meeting',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'duration_seconds' => 120,
        'raw_text' => 'Raw meeting transcript.',
        'summary_text' => 'Meeting summary.',
        'summary_status' => 'complete',
    ]);

    expect(fn () => app(TranscriptExportService::class)->export($transcript, 'xlsx', 'summary'))
        ->toThrow(RuntimeException::class, 'Excel exports require the PHP zip and gd extensions.');
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

/**
 * @return array<string, mixed>
 */
function deepgramRuntimeMetadata(): array
{
    return [
        'listen_url' => (string) config('services.deepgram.listen_url'),
        'timeout' => 120,
    ];
}

function availableFfmpegBinary(): ?string
{
    $binary = config('services.ffmpeg.binary', 'ffmpeg');
    $binary = is_string($binary) && trim($binary) !== '' ? trim($binary) : 'ffmpeg';

    $process = new Process([$binary, '-version']);
    $process->setTimeout(5);

    try {
        $process->run();
    } catch (Throwable) {
        return null;
    }

    return $process->isSuccessful() ? $binary : null;
}
