<?php

use App\Models\API;
use App\Models\TranscriptionProviderSetting;
use App\Services\GroqSpeechToTextService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

it('publishes the transcribe batch limit in the license status response', function () {
    $license = API::query()->create([
        'app_name' => 'Audio Limit Status Test '.uniqid(),
        'app_token' => 'audio-limit-status-license-'.uniqid(),
        'can_post' => true,
        'can_get' => true,
        'is_active' => true,
    ]);

    $this->withToken($license->app_token)
        ->getJson('/api/license/status')
        ->assertOk()
        ->assertJsonPath('apis.transcribe.supports_batch', true)
        ->assertJsonPath('apis.transcribe.max_batch_clips', 20)
        ->assertJsonPath('apis.transcribe.max_batch_duration_ms', 1200000)
        ->assertJsonPath('apis.transcribe.max_batch_duration_minutes', 20);
});

it('rejects transcribe audio over the twenty minute batch limit', function () {
    $license = API::query()->create([
        'app_name' => 'Audio Limit Test '.uniqid(),
        'app_token' => 'audio-limit-license-'.uniqid(),
        'can_post' => true,
        'can_get' => true,
        'is_active' => true,
    ]);

    Http::fake();

    $this->withToken($license->app_token)
        ->post('/api/transcribe', [
            'audio' => UploadedFile::fake()->createWithContent('clip.wav', 'fake audio'),
            'language_code' => 'en',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 1200001,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Audio is too big.');

    Http::assertNothingSent();
});

it('uses provider priority for every batched clip and wraps fallback attempts', function () {
    $license = API::query()->create([
        'app_name' => 'Audio Queue Test '.uniqid(),
        'app_token' => 'audio-queue-license-'.uniqid(),
        'can_post' => true,
        'can_get' => true,
        'is_active' => true,
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'deepgram',
        'api_key' => 'deepgram-key',
        'model' => 'nova-3',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    TranscriptionProviderSetting::query()->create([
        'provider' => 'groq_transcription',
        'api_key' => 'groq-key',
        'model' => GroqSpeechToTextService::MODEL_WHISPER_LARGE_V3_TURBO,
        'is_enabled' => true,
        'sort_order' => 1,
    ]);

    Http::fake([
        config('services.deepgram.listen_url').'*' => Http::sequence()
            ->push([
                'results' => [
                    'channels' => [[
                        'alternatives' => [[
                            'transcript' => 'Deepgram first clip.',
                            'words' => [],
                        ]],
                    ]],
                ],
            ])
            ->push(['error' => 'temporary failure'], 500),
        config('services.groq.transcription_url') => Http::response([
            'text' => 'Groq rescued second clip.',
            'segments' => [],
        ]),
    ]);

    $this->withToken($license->app_token)
        ->post('/api/transcribe', [
            'audio' => [
                UploadedFile::fake()->createWithContent('clip-1.wav', 'fake audio 1'),
                UploadedFile::fake()->createWithContent('clip-2.wav', 'fake audio 2'),
            ],
            'language_code' => ['en', 'en'],
            'clip_index' => [1, 2],
            'clip_start_ms' => [0, 300000],
            'clip_end_ms' => [300000, 600000],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(2, 'clips')
        ->assertJsonPath('clips.0.text', 'Deepgram first clip.')
        ->assertJsonPath('clips.0.attempted_providers', ['deepgram'])
        ->assertJsonPath('clips.0.fallback.used', false)
        ->assertJsonPath('clips.1.text', 'Groq rescued second clip.')
        ->assertJsonPath('clips.1.attempted_providers', ['deepgram', 'groq_transcription'])
        ->assertJsonPath('clips.1.fallback.used', true)
        ->assertJsonPath('fallback.used', true);
});

it('sends batched clips to runpod when runpod is the first provider', function () {
    $license = API::query()->create([
        'app_name' => 'RunPod Batch Test '.uniqid(),
        'app_token' => 'runpod-batch-license-'.uniqid(),
        'can_post' => true,
        'can_get' => true,
        'is_active' => true,
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => 'runpod',
        'api_key' => 'runpod-key',
        'model' => 'serverless-transcriptor',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => [
            'runsync_url' => 'https://runpod.test/v2/endpoint/runsync',
        ],
    ]);

    config([
        'app.url' => 'https://server.test',
    ]);

    Http::fake([
        'https://runpod.test/v2/endpoint/run' => Http::response([
            'id' => 'batch-job-1',
            'status' => 'IN_QUEUE',
        ]),
        'https://runpod.test/v2/endpoint/status/batch-job-1' => Http::response([
            'status' => 'COMPLETED',
            'output' => [
                'clips' => [
                    [
                        'queue_index' => 0,
                        'clip_index' => 1,
                        'clip_start_ms' => 0,
                        'clip_end_ms' => 300000,
                        'text' => 'RunPod first clip.',
                        'timestamps' => [['text' => 'RunPod first clip.', 'start' => 0, 'end' => 1]],
                    ],
                    [
                        'queue_index' => 1,
                        'clip_index' => 2,
                        'clip_start_ms' => 300000,
                        'clip_end_ms' => 600000,
                        'text' => 'RunPod second clip.',
                        'timestamps' => [['text' => 'RunPod second clip.', 'start' => 0, 'end' => 1]],
                    ],
                ],
            ],
        ]),
    ]);

    $this->withToken($license->app_token)
        ->post('/api/transcribe', [
            'audio' => [
                UploadedFile::fake()->createWithContent('clip-1.wav', 'fake audio 1'),
                UploadedFile::fake()->createWithContent('clip-2.wav', 'fake audio 2'),
            ],
            'language_code' => ['en', 'en'],
            'clip_index' => [1, 2],
            'clip_start_ms' => [0, 300000],
            'clip_end_ms' => [300000, 600000],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(2, 'clips')
        ->assertJsonPath('clips.0.text', 'RunPod first clip.')
        ->assertJsonPath('clips.1.text', 'RunPod second clip.')
        ->assertJsonPath('clips.0.attempted_providers', ['runpod'])
        ->assertJsonPath('fallback.used', false);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://runpod.test/v2/endpoint/run'
        && $request->hasHeader('Authorization', 'Bearer runpod-key')
        && is_array($request['input']['clips'] ?? null)
        && count($request['input']['clips']) === 2
        && $request['input']['clips'][0]['clip_index'] === 1
        && $request['input']['clips'][1]['clip_index'] === 2
        && is_string($request['input']['clips'][0]['audio_base64'] ?? null)
        && base64_decode($request['input']['clips'][0]['audio_base64'], true) === 'fake audio 1');
});
