<?php

use App\Models\TranscriptionProviderSetting;
use App\Services\AppSettingsService;
use App\Services\WebTranscriptProcessor;
use Illuminate\Support\Facades\Http;

test('existing provider rows remain connected without runtime metadata', function () {
    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_DEEPGRAM,
        'api_key' => 'deepgram-existing-key',
        'model' => AppSettingsService::DEEPGRAM_MODEL_NOVA_3,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_GEMINI,
        'api_key' => 'gemini-existing-key',
        'model' => AppSettingsService::GEMINI_MODEL_FLASH_LITE,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    $settings = app(AppSettingsService::class);
    $transcribers = $settings->orderedConnectedProviders('transcriber');
    $textFixers = $settings->orderedConnectedProviders('text_fixer');

    expect($transcribers)
        ->toHaveCount(1)
        ->and($transcribers[0]['provider'])->toBe(AppSettingsService::PROVIDER_DEEPGRAM)
        ->and($transcribers[0]['metadata']['listen_url'])->toBe(config('services.deepgram.listen_url'))
        ->and($transcribers[0]['metadata']['timeout'])->toBe(config('services.deepgram.timeout'))
        ->and($textFixers)
        ->toHaveCount(1)
        ->and($textFixers[0]['provider'])->toBe(AppSettingsService::PROVIDER_GEMINI)
        ->and($textFixers[0]['metadata']['endpoint_template'])
        ->toBe(rtrim((string) config('services.gemini.base_url'), '/').'/models/%s:generateContent')
        ->and($settings->geminiTimeout())->toBe((int) config('services.gemini.timeout'))
        ->and($settings->geminiMaxRetries())->toBe((int) config('services.gemini.max_retries'));

    Http::fake([
        rtrim((string) config('services.gemini.base_url'), '/').'/*' => Http::response([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'text' => 'Processed current transcript.',
                            'timestamps' => [],
                        ]),
                    ]],
                ],
            ]],
        ]),
    ]);

    $processor = app(WebTranscriptProcessor::class);
    $cleanText = new ReflectionMethod($processor, 'cleanText');
    $processed = $cleanText->invoke(
        $processor,
        'Current transcript.',
        'Summarize this transcript.',
        'summarize',
    );

    expect($processed['text'])->toBe('Processed current transcript.');

    Http::assertSent(fn ($request): bool => str_contains(
        $request->url(),
        '/models/'.AppSettingsService::GEMINI_MODEL_FLASH_LITE.':generateContent',
    ));
});
