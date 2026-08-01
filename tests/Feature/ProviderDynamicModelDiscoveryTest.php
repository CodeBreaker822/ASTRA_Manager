<?php

use App\Models\TranscriptionProviderSetting;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use App\Services\AppSettingsService;
use App\Services\GroqModelCatalogService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('api manager discovers every compatible gemini text model using a newly pasted key', function () {
    $manager = createProviderDiscoveryManager();
    $modelsUrl = 'https://gemini-discovery.test/v1beta/models';

    config(['services.gemini.models_url' => $modelsUrl]);

    Http::fake([
        $modelsUrl.'*' => Http::response([
            'models' => [
                geminiDiscoveryModel('gemini-3.1-flash-lite'),
                geminiDiscoveryModel('gemini-2.5-pro'),
                geminiDiscoveryModel('gemini-2.5-flash'),
                geminiDiscoveryModel('gemini-2.5-flash-image'),
            ],
        ]),
    ]);

    $this->actingAs($manager)
        ->postJson(route('api.transcription-providers.models'), [
            'provider' => AppSettingsService::PROVIDER_GEMINI,
            'api_key' => 'new-gemini-key',
        ])
        ->assertOk()
        ->assertJsonPath('models', [
            'gemini-3.1-flash-lite',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
        ]);

    Http::assertSent(function (Request $request) use ($modelsUrl): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return strtok($request->url(), '?') === $modelsUrl
            && ($query['key'] ?? null) === 'new-gemini-key';
    });
});

test('api manager discovers every non archived mistral chat completion model', function () {
    $manager = createProviderDiscoveryManager();
    $modelsUrl = 'https://mistral-discovery.test/v1/models';

    config(['services.mistral.models_url' => $modelsUrl]);

    Http::fake([
        $modelsUrl => Http::response([
            'data' => [
                mistralDiscoveryModel('mistral-small-latest', true),
                mistralDiscoveryModel('mistral-large-latest', true),
                mistralDiscoveryModel('voxtral-small-latest', true),
                mistralDiscoveryModel('mistral-embed', false),
                [...mistralDiscoveryModel('old-chat-model', true), 'archived' => true],
            ],
        ]),
    ]);

    $this->actingAs($manager)
        ->postJson(route('api.transcription-providers.models'), [
            'provider' => AppSettingsService::PROVIDER_MISTRAL,
            'api_key' => 'new-mistral-key',
        ])
        ->assertOk()
        ->assertJsonPath('models', [
            'mistral-small-latest',
            'mistral-large-latest',
            'voxtral-small-latest',
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === $modelsUrl
        && $request->hasHeader('Authorization', 'Bearer new-mistral-key'));
});

test('api manager separates groq transcription and text models loaded with a newly pasted key', function () {
    $manager = createProviderDiscoveryManager();
    $modelsUrl = 'https://groq-discovery.test/openai/v1/models';

    config(['services.groq.models_url' => $modelsUrl]);

    Http::fake([$modelsUrl => Http::response(groqDiscoveryCatalog())]);

    $this->actingAs($manager)
        ->postJson(route('api.transcription-providers.models'), [
            'provider' => AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
            'api_key' => 'new-groq-key',
        ])
        ->assertOk()
        ->assertJsonPath('models', [
            'whisper-large-v3',
            'whisper-large-v3-turbo',
            'distil-whisper-large-v3-en',
        ]);

    $this->actingAs($manager)
        ->postJson(route('api.transcription-providers.models'), [
            'provider' => AppSettingsService::PROVIDER_GROQ_TEXT_FIXER,
            'api_key' => 'new-groq-key',
        ])
        ->assertOk()
        ->assertJsonPath('models', [
            'llama-3.1-8b-instant',
            'openai/gpt-oss-20b',
            'groq/compound',
        ]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $modelsUrl
        && $request->hasHeader('Authorization', 'Bearer new-groq-key'));
});

test('groq model catalogs are cached separately for different api keys', function () {
    $modelsUrl = 'https://groq-cache-scope.test/openai/v1/models';

    Http::fake(function (Request $request) {
        $model = $request->hasHeader('Authorization', 'Bearer first-groq-key')
            ? 'first-key-chat-model'
            : 'second-key-chat-model';

        return Http::response([
            'data' => [['id' => $model, 'active' => true]],
        ]);
    });

    expect((new GroqModelCatalogService('first-groq-key', $modelsUrl, 10))->cleanerModelIds())
        ->toBe(['first-key-chat-model'])
        ->and((new GroqModelCatalogService('second-groq-key', $modelsUrl, 10))->cleanerModelIds())
        ->toBe(['second-key-chat-model']);

    Http::assertSentCount(2);
});

test('a newly discovered model can be selected before the provider key is saved', function () {
    $manager = createProviderDiscoveryManager();
    $modelsUrl = 'https://gemini-save.test/v1beta/models';

    config([
        'services.gemini.models_url' => $modelsUrl,
        'services.gemini.models' => ['gemini-3.1-flash-lite'],
    ]);

    Http::fake([
        $modelsUrl.'*' => Http::response([
            'models' => [
                geminiDiscoveryModel('gemini-3.1-flash-lite'),
                geminiDiscoveryModel('gemini-2.5-pro'),
                geminiDiscoveryModel('gemini-2.5-flash'),
            ],
        ]),
    ]);

    $this->actingAs($manager)
        ->postJson(route('api.transcription-providers.update'), [
            'providers' => [
                AppSettingsService::PROVIDER_GEMINI => [
                    'api_key' => 'new-gemini-save-key',
                    'model' => 'gemini-2.5-pro',
                    'is_enabled' => 1,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonFragment([
            'provider' => AppSettingsService::PROVIDER_GEMINI,
            'model' => 'gemini-2.5-pro',
            'configured' => true,
        ]);

    expect(TranscriptionProviderSetting::query()
        ->where('provider', AppSettingsService::PROVIDER_GEMINI)
        ->where('model', 'gemini-2.5-pro')
        ->exists())->toBeTrue();
});

test('a newly discovered mistral chat model is saved without collapsing to the fallback', function () {
    $manager = createProviderDiscoveryManager();
    $modelsUrl = 'https://mistral-save.test/v1/models';

    config([
        'services.mistral.models_url' => $modelsUrl,
        'services.mistral.text_fixer_models' => ['mistral-small-2603'],
    ]);

    Http::fake([
        $modelsUrl => Http::response([
            'data' => [
                mistralDiscoveryModel('mistral-small-latest', true),
                mistralDiscoveryModel('mistral-large-latest', true),
                mistralDiscoveryModel('voxtral-small-latest', true),
            ],
        ]),
    ]);

    $this->actingAs($manager)
        ->postJson(route('api.transcription-providers.update'), [
            'providers' => [
                AppSettingsService::PROVIDER_MISTRAL => [
                    'api_key' => 'new-mistral-save-key',
                    'model' => 'mistral-large-latest',
                    'is_enabled' => 1,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonFragment([
            'provider' => AppSettingsService::PROVIDER_MISTRAL,
            'model' => 'mistral-large-latest',
            'configured' => true,
        ]);

    expect(TranscriptionProviderSetting::query()
        ->where('provider', AppSettingsService::PROVIDER_MISTRAL)
        ->where('model', 'mistral-large-latest')
        ->exists())->toBeTrue();
});

test('newly discovered groq transcription and text models persist without fallback coercion', function () {
    $manager = createProviderDiscoveryManager();
    $modelsUrl = 'https://groq-save.test/openai/v1/models';

    config([
        'services.groq.models_url' => $modelsUrl,
        'services.groq.transcription_models' => ['whisper-large-v3'],
        'services.groq.text_fixer_models' => ['llama-3.1-8b-instant'],
    ]);

    Http::fake([$modelsUrl => Http::response(groqDiscoveryCatalog())]);

    $this->actingAs($manager)
        ->postJson(route('api.transcription-providers.update'), [
            'providers' => [
                AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION => [
                    'api_key' => 'groq-transcription-save-key',
                    'model' => 'distil-whisper-large-v3-en',
                    'is_enabled' => 1,
                ],
                AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => [
                    'api_key' => 'groq-cleaner-save-key',
                    'model' => 'openai/gpt-oss-20b',
                    'is_enabled' => 1,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonFragment([
            'provider' => AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
            'model' => 'distil-whisper-large-v3-en',
            'configured' => true,
        ])
        ->assertJsonFragment([
            'provider' => AppSettingsService::PROVIDER_GROQ_TEXT_FIXER,
            'model' => 'openai/gpt-oss-20b',
            'configured' => true,
        ]);

    expect(TranscriptionProviderSetting::query()
        ->where('provider', AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION)
        ->where('model', 'distil-whisper-large-v3-en')
        ->exists())->toBeTrue()
        ->and(TranscriptionProviderSetting::query()
            ->where('provider', AppSettingsService::PROVIDER_GROQ_TEXT_FIXER)
            ->where('model', 'openai/gpt-oss-20b')
            ->exists())->toBeTrue();
});

/**
 * @return array{name: string, supportedGenerationMethods: array<int, string>}
 */
function geminiDiscoveryModel(string $id): array
{
    return [
        'name' => 'models/'.$id,
        'supportedGenerationMethods' => ['generateContent'],
    ];
}

/**
 * @return array{id: string, capabilities: array{completion_chat: bool}, archived: bool}
 */
function mistralDiscoveryModel(string $id, bool $completionChat): array
{
    return [
        'id' => $id,
        'capabilities' => ['completion_chat' => $completionChat],
        'archived' => false,
    ];
}

/**
 * @return array{data: array<int, array{id: string, active: bool}>}
 */
function groqDiscoveryCatalog(): array
{
    return [
        'data' => [
            ['id' => 'whisper-large-v3', 'active' => true],
            ['id' => 'whisper-large-v3-turbo', 'active' => true],
            ['id' => 'distil-whisper-large-v3-en', 'active' => true],
            ['id' => 'llama-3.1-8b-instant', 'active' => true],
            ['id' => 'openai/gpt-oss-20b', 'active' => true],
            ['id' => 'groq/compound', 'active' => true],
            ['id' => 'canopylabs/orpheus-v1-english', 'active' => true],
            ['id' => 'openai/gpt-oss-safeguard-20b', 'active' => true],
            ['id' => 'inactive-chat-model', 'active' => false],
        ],
    ];
}

function createProviderDiscoveryManager(): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_PROVIDER_DISCOVERY_'.uniqid(),
        'position_name' => 'Provider Discovery Manager',
        'assigned_office' => 'web',
        'category' => 'api',
        'description' => 'Can discover provider models.',
        'is_active' => true,
    ]);

    UserPermissions::query()->create([
        'position_id' => $position->id,
        'permission_name' => 'API-manage_api',
    ]);

    return User::factory()->create(['position_id' => $position->id]);
}
