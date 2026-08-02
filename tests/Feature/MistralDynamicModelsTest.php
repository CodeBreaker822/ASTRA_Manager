<?php

use App\Models\API;
use App\Models\TranscriptionApiRequestLog;
use App\Models\TranscriptionProviderSetting;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\LicenseKeyService;
use App\Services\MistralModelCatalogService;
use App\Services\MistralSpeechToTextService;
use App\Services\MistralTranscriptCleanerService;
use Database\Seeders\PlanTierSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function mistralMixedModelCatalog(): array
{
    return [
        'data' => [
            [
                'id' => 'voxtral-mini-latest',
                'capabilities' => ['completion_chat' => false],
                'archived' => false,
            ],
            [
                'id' => 'voxtral-mini-2602',
                'capabilities' => ['completion_chat' => false],
                'archived' => false,
            ],
            [
                'id' => 'voxtral-mini-transcribe-realtime-2602',
                'capabilities' => ['completion_chat' => false],
                'archived' => false,
            ],
            [
                'id' => 'voxtral-mini-tts-2603',
                'capabilities' => ['completion_chat' => false],
                'archived' => false,
            ],
            [
                'id' => 'voxtral-small-latest',
                'capabilities' => ['completion_chat' => true],
                'archived' => false,
            ],
            [
                'id' => 'mistral-small-latest',
                'capabilities' => ['completion_chat' => true],
                'archived' => false,
            ],
            [
                'id' => 'codestral-embed-latest',
                'capabilities' => ['completion_chat' => false],
                'archived' => false,
            ],
            [
                'id' => 'archived-chat-model',
                'capabilities' => ['completion_chat' => true],
                'archived' => true,
            ],
        ],
    ];
}

test('mistral model catalog separates offline transcription from transcript cleaner models', function () {
    $modelsUrl = 'https://mistral-models.test/v1/models';

    Http::fake([$modelsUrl => Http::response(mistralMixedModelCatalog())]);

    $catalog = new MistralModelCatalogService('mistral-model-key', $modelsUrl, 10);

    expect($catalog->transcriptionModelIds())
        ->toBe(['voxtral-mini-latest', 'voxtral-mini-2602'])
        ->and($catalog->cleanerModelIds())
        ->toBe(['voxtral-small-latest', 'mistral-small-latest']);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === $modelsUrl
        && $request->hasHeader('Authorization', 'Bearer mistral-model-key'));
});

test('api manager exposes separate dynamically fetched mistral transcription and cleaner cards', function () {
    config([
        'services.mistral.models_url' => 'https://mistral-cards.test/v1/models',
        'services.mistral.transcription_url' => 'https://mistral-cards.test/v1/audio/transcriptions',
        'services.mistral.chat_completions_url' => 'https://mistral-cards.test/v1/chat/completions',
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION,
        'api_key' => 'shared-mistral-key',
        'model' => 'voxtral-mini-latest',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_MISTRAL,
        'api_key' => 'shared-mistral-key',
        'model' => 'mistral-small-latest',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    Http::fake([
        'https://mistral-cards.test/v1/models' => Http::response(mistralMixedModelCatalog()),
    ]);

    $cards = collect(app(AppSettingsService::class)->providerCards());
    $transcriber = $cards->firstWhere('provider', AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION);
    $cleaner = $cards->firstWhere('provider', AppSettingsService::PROVIDER_MISTRAL);

    expect($transcriber)
        ->not->toBeNull()
        ->and($transcriber['category'])->toBe('transcriber')
        ->and($transcriber['models'])->toBe(['voxtral-mini-latest', 'voxtral-mini-2602'])
        ->and($transcriber['endpoint'])->toBe('https://mistral-cards.test/v1/audio/transcriptions')
        ->and($cleaner)
        ->not->toBeNull()
        ->and($cleaner['category'])->toBe('text_fixer')
        ->and($cleaner['models'])->toBe(['mistral-small-latest', 'voxtral-small-latest'])
        ->and($cleaner['endpoint'])->toBe('https://mistral-cards.test/v1/chat/completions');
});

test('mistral transcription service fetches valid models and transcribes with the selected voxtral model', function () {
    $modelsUrl = 'https://mistral-transcribe.test/v1/models';
    $transcriptionUrl = 'https://mistral-transcribe.test/v1/audio/transcriptions';

    Http::fake([
        $modelsUrl => Http::response(mistralMixedModelCatalog()),
        $transcriptionUrl => Http::response([
            'model' => 'voxtral-mini-2602',
            'text' => 'Mistral transcribed this audio.',
            'segments' => [[
                'text' => 'Mistral transcribed this audio.',
                'start' => 0.0,
                'end' => 2.0,
                'speaker_id' => 'speaker_0',
            ]],
        ]),
    ]);

    $audio = UploadedFile::fake()->createWithContent('mistral.wav', 'test audio');
    $service = new MistralSpeechToTextService(
        apiKey: 'mistral-transcription-key',
        endpoint: $transcriptionUrl,
        modelId: 'voxtral-mini-2602',
        modelsUrl: $modelsUrl,
        timeout: 10,
    );
    $payload = (new ReflectionMethod($service, 'payload'))->invoke($service, ['language_code' => 'en']);
    $result = $service->transcribe($audio, ['language_code' => 'en']);

    expect($service->getAvailableModelIds())
        ->toBe(['voxtral-mini-latest', 'voxtral-mini-2602'])
        ->and($payload['model'])->toBe('voxtral-mini-2602')
        ->and($payload['language'])->toBe('en')
        ->and($result['text'])->toBe('Mistral transcribed this audio.')
        ->and($result['timestamps'][0]['speaker_id'])->toBe('speaker_0');

    Http::assertSent(fn ($request): bool => $request->url() === $transcriptionUrl);

});

test('mistral cleaner fetches only chat-capable text models when no model list is supplied', function () {
    $modelsUrl = 'https://mistral-cleaner.test/v1/models';
    $chatUrl = 'https://mistral-cleaner.test/v1/chat/completions';

    Http::fake([
        $modelsUrl => Http::response(mistralMixedModelCatalog()),
        $chatUrl => Http::response([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'content' => json_encode([
                        'text' => 'Clean Mistral transcript.',
                        'timestamps' => [],
                    ]),
                ],
            ]],
        ]),
    ]);

    $service = new MistralTranscriptCleanerService(
        apiKey: 'mistral-cleaner-key',
        model: 'mistral-small-latest',
        endpoint: $chatUrl,
        modelsUrl: $modelsUrl,
        timeout: 10,
        maxRetries: 1,
    );
    $result = $service->clean('Raw Mistral transcript.');

    expect($service->getAvailableModelIds())
        ->toBe(['voxtral-small-latest', 'mistral-small-latest'])
        ->and($result['model'])->toBe('mistral-small-latest')
        ->and($result['text'])->toBe('Clean Mistral transcript.');
});

test('transcription api uses the dynamically selected mistral voxtral provider', function () {
    $modelsUrl = 'https://mistral-api.test/v1/models';
    $transcriptionUrl = 'https://mistral-api.test/v1/audio/transcriptions';

    config([
        'services.mistral.models_url' => $modelsUrl,
        'services.mistral.transcription_url' => $transcriptionUrl,
    ]);

    app(Kernel::class)->call('db:seed', ['--class' => PlanTierSeeder::class]);
    $user = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 100,
    ]);
    $license = app(LicenseKeyService::class)->provisionForUser($user);

    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION,
        'api_key' => 'mistral-api-key',
        'model' => 'voxtral-mini-2602',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => [
            'transcription_url' => $transcriptionUrl,
            'models_url' => $modelsUrl,
            'timeout' => 10,
        ],
    ]);

    Http::fake([
        $modelsUrl => Http::response(mistralMixedModelCatalog()),
        $transcriptionUrl => Http::response([
            'model' => 'voxtral-mini-2602',
            'text' => 'Mistral API transcription.',
            'segments' => [],
        ]),
    ]);

    $this->withToken($license->app_token)
        ->post('/api/transcribe', [
            'audio' => UploadedFile::fake()->createWithContent('mistral-api.wav', 'test audio'),
            'language_code' => 'en',
            'clip_index' => 0,
            'clip_start_ms' => 0,
            'clip_end_ms' => 1000,
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('text', 'Mistral API transcription.')
        ->assertJsonPath('clips.0.attempted_providers', [AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION]);

    Http::assertSent(fn ($request): bool => $request->url() === $transcriptionUrl);

    $this->assertDatabaseHas(TranscriptionApiRequestLog::class, [
        'operation' => 'transcribe_provider',
        'status' => 'provider_succeeded',
        'provider' => AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION,
        'model' => 'voxtral-mini-2602',
    ]);
});

test('polish api uses a dynamically fetched mistral cleaner model', function () {
    $modelsUrl = 'https://mistral-polish.test/v1/models';
    $chatUrl = 'https://mistral-polish.test/v1/chat/completions';

    config([
        'services.mistral.models_url' => $modelsUrl,
        'services.mistral.chat_completions_url' => $chatUrl,
    ]);

    $license = API::query()->create([
        'app_name' => 'mistral-polish-test',
        'app_token' => 'mistral-polish-license-'.uniqid(),
        'can_post' => true,
        'can_get' => true,
        'is_active' => true,
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_MISTRAL,
        'api_key' => 'mistral-polish-key',
        'model' => 'mistral-small-latest',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => [
            'chat_completions_url' => $chatUrl,
            'models_url' => $modelsUrl,
            'timeout' => 10,
            'max_retries' => 1,
        ],
    ]);

    Http::fake([
        $modelsUrl => Http::response(mistralMixedModelCatalog()),
        $chatUrl => Http::response([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'content' => json_encode([
                        'text' => 'Polished by dynamic Mistral.',
                        'timestamps' => [],
                    ]),
                ],
            ]],
        ]),
    ]);

    $this->withToken($license->app_token)
        ->postJson('/api/polish', [
            'text' => 'Raw transcript for Mistral.',
            'instruction' => 'Polish this transcript.',
        ])
        ->assertOk()
        ->assertJsonPath('text', 'Polished by dynamic Mistral.')
        ->assertJsonPath('model', AppSettingsService::PUBLIC_MODEL);

    Http::assertSent(fn ($request): bool => $request->url() === $chatUrl
        && $request['model'] === 'mistral-small-latest');

    $this->assertDatabaseHas(TranscriptionApiRequestLog::class, [
        'operation' => 'polish_provider',
        'status' => 'provider_succeeded',
        'provider' => AppSettingsService::PROVIDER_MISTRAL,
        'model' => 'mistral-small-latest',
    ]);
});
