<?php

use App\Models\API;
use App\Models\TranscriptionProviderSetting;
use App\Services\Transcription\AppSettingsService;
use App\Services\Transcription\GeminiModelCatalogService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function geminiModelPageOne(): array
{
    return [
        'models' => [
            [
                'name' => 'models/gemini-3.1-flash-lite',
                'displayName' => 'Gemini 3.1 Flash-Lite',
                'supportedGenerationMethods' => ['generateContent', 'countTokens'],
            ],
            [
                'name' => 'models/gemini-embedding-001',
                'supportedGenerationMethods' => ['embedContent'],
            ],
            [
                'name' => 'models/gemini-3.1-flash-image',
                'supportedGenerationMethods' => ['generateContent'],
            ],
            [
                'name' => 'models/gemini-3.1-flash-tts-preview',
                'supportedGenerationMethods' => ['generateContent'],
            ],
            [
                'name' => 'models/gemini-3.1-flash-live-preview',
                'supportedGenerationMethods' => ['generateContent'],
            ],
            [
                'name' => 'models/gemma-3-27b-it',
                'supportedGenerationMethods' => ['generateContent'],
            ],
            [
                'name' => 'models/gemini-deprecated-text-model',
                'description' => 'Deprecated text model.',
                'supportedGenerationMethods' => ['generateContent'],
            ],
        ],
        'nextPageToken' => 'second-page',
    ];
}

function geminiModelPageTwo(): array
{
    return [
        'models' => [
            [
                'name' => 'models/gemini-2.5-pro',
                'displayName' => 'Gemini 2.5 Pro',
                'supportedGenerationMethods' => ['generateContent', 'countTokens'],
            ],
            [
                'name' => 'models/gemini-2.5-flash-native-audio-dialog',
                'supportedGenerationMethods' => ['generateContent'],
            ],
            [
                'name' => 'models/gemini-robotics-er-1.5-preview',
                'supportedGenerationMethods' => ['generateContent'],
            ],
        ],
    ];
}

test('gemini catalog follows pagination and returns only text cleaner models', function () {
    $modelsUrl = 'https://gemini-catalog.test/v1beta/models';

    Http::fake(function (Request $request) use ($modelsUrl) {
        expect(strtok($request->url(), '?'))->toBe($modelsUrl);

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        expect($query['key'] ?? null)->toBe('gemini-model-key')
            ->and($query['pageSize'] ?? null)->toBe('1000');

        return Http::response(($query['pageToken'] ?? null) === 'second-page'
            ? geminiModelPageTwo()
            : geminiModelPageOne());
    });

    $catalog = new GeminiModelCatalogService('gemini-model-key', $modelsUrl, 10);

    expect($catalog->cleanerModelIds())
        ->toBe([
            'gemini-3.1-flash-lite',
            'gemini-deprecated-text-model',
            'gemini-2.5-pro',
        ]);

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['pageToken'] ?? null) === 'second-page';
    });
});

test('api manager exposes dynamically fetched gemini text models', function () {
    $modelsUrl = 'https://gemini-cards.test/v1beta/models';

    config([
        'services.gemini.base_url' => 'https://gemini-cards.test/v1beta',
        'services.gemini.models_url' => $modelsUrl,
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_GEMINI,
        'api_key' => 'gemini-cards-key',
        'model' => 'gemini-2.5-pro',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    Http::fake(function (Request $request) use ($modelsUrl) {
        if (strtok($request->url(), '?') === $modelsUrl) {
            return Http::response([
                'models' => [
                    geminiModelPageOne()['models'][0],
                    geminiModelPageTwo()['models'][0],
                    geminiModelPageOne()['models'][2],
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $card = collect(app(AppSettingsService::class)->providerCards())
        ->firstWhere('provider', AppSettingsService::PROVIDER_GEMINI);

    expect($card)
        ->not->toBeNull()
        ->and($card['category'])->toBe('text_fixer')
        ->and($card['model'])->toBe('gemini-2.5-pro')
        ->and($card['models'])->toBe(['gemini-2.5-pro', 'gemini-3.1-flash-lite'])
        ->and($card['metadata']['models_url'])->toBe($modelsUrl);
});

test('polish api uses the dynamically selected gemini model', function () {
    $baseUrl = 'https://gemini-polish.test/v1beta';
    $modelsUrl = $baseUrl.'/models';

    config([
        'services.gemini.base_url' => $baseUrl,
        'services.gemini.models_url' => $modelsUrl,
    ]);

    $license = API::query()->create([
        'app_name' => 'gemini-polish-test',
        'app_token' => 'gemini-polish-license-'.uniqid(),
        'can_post' => true,
        'can_get' => true,
        'is_active' => true,
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_GEMINI,
        'api_key' => 'gemini-polish-key',
        'model' => 'gemini-2.5-pro',
        'is_enabled' => true,
        'sort_order' => 0,
        'metadata' => [
            'models_url' => $modelsUrl,
            'endpoint_template' => $baseUrl.'/models/%s:generateContent',
            'generate_content_url_template' => $baseUrl.'/models/%s:generateContent',
            'timeout' => 10,
            'max_retries' => 1,
        ],
    ]);

    Http::fake(function (Request $request) use ($modelsUrl) {
        if (strtok($request->url(), '?') === $modelsUrl) {
            return Http::response([
                'models' => [
                    geminiModelPageOne()['models'][0],
                    geminiModelPageTwo()['models'][0],
                ],
            ]);
        }

        if (str_contains($request->url(), '/models/gemini-2.5-pro:generateContent')) {
            return Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'text' => 'Polished by dynamic Gemini.',
                                'timestamps' => [],
                            ]),
                        ]],
                    ],
                ]],
            ]);
        }

        return Http::response([], 404);
    });

    $this->withToken($license->app_token)
        ->postJson('/api/polish', [
            'text' => 'Raw transcript for Gemini.',
            'instruction' => 'Polish this transcript.',
        ])
        ->assertOk()
        ->assertJsonPath('text', 'Polished by dynamic Gemini.')
        ->assertJsonPath('model', AppSettingsService::PUBLIC_MODEL);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/models/gemini-2.5-pro:generateContent')
            && ($query['key'] ?? null) === 'gemini-polish-key';
    });
});
