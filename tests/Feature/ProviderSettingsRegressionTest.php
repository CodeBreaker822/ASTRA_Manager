<?php

use App\Models\API;
use App\Models\Transcript;
use App\Models\TranscriptionProviderSetting;
use App\Models\TranscriptProject;
use App\Models\User;
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

test('web summaries ask the ai for the jerva report format', function () {
    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_GEMINI,
        'api_key' => 'gemini-existing-key',
        'model' => AppSettingsService::GEMINI_MODEL_FLASH_LITE,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    $user = User::factory()->create(['plan' => 'free']);
    $project = TranscriptProject::query()->create([
        'user_id' => $user->id,
        'title' => 'Report format',
    ]);
    $transcript = Transcript::query()->create([
        'project_id' => $project->id,
        'source' => 'upload',
        'status' => 'completed',
        'duration_seconds' => 60,
        'raw_text' => 'Discussed the request, approved it, and assigned the next step.',
        'summary_status' => 'processing',
    ]);

    Http::fake([
        rtrim((string) config('services.gemini.base_url'), '/').'/*' => Http::response([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'text' => "## Executive Summary\nThe request was approved.\n\n## Next Steps\n- **Owner:** Operations",
                            'timestamps' => [],
                        ]),
                    ]],
                ],
            ]],
        ]),
    ]);

    $summary = app(WebTranscriptProcessor::class)->summarize($transcript);

    expect($summary)
        ->toContain('## Executive Summary')
        ->and($transcript->refresh()->summary_text)
        ->toContain('- **Owner:** Operations');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();
        $userText = (string) data_get($payload, 'contents.0.parts.0.text');
        $decoded = json_decode($userText, true);
        $instructions = (string) ($decoded['instructions'] ?? '');

        return str_contains($instructions, 'Organize the report by topic')
            && str_contains($instructions, 'Format headings as Markdown headings using ## or ###')
            && str_contains($instructions, 'format lists as Markdown bullets using -');
    });
});

test('api summary requests preserve the jerva markdown report format when chunked', function () {
    config(['services.transcript_polishing.chunk_characters' => 2000]);

    $license = API::query()->create([
        'app_name' => 'jerva-summary-format-test',
        'app_token' => 'summary-format-license-'.uniqid(),
        'can_post' => true,
        'can_get' => true,
        'is_active' => true,
    ]);

    TranscriptionProviderSetting::query()->create([
        'provider' => AppSettingsService::PROVIDER_GEMINI,
        'api_key' => 'gemini-existing-key',
        'model' => AppSettingsService::GEMINI_MODEL_FLASH_LITE,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);

    Http::fake([
        rtrim((string) config('services.gemini.base_url'), '/').'/*' => Http::response([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'text' => "## Executive Summary\nThe request was approved.\n\n## Next Steps\n- **Owner:** Operations",
                            'timestamps' => [],
                        ]),
                    ]],
                ],
            ]],
        ]),
    ]);

    $this->withToken($license->app_token)
        ->postJson('/api/polish', [
            'task' => 'summarize',
            'text' => str_repeat('The committee approved the request and assigned the next step. ', 80),
            'instruction' => 'Create a concise, professional report from this transcript.',
        ])
        ->assertOk()
        ->assertJsonPath('text', "## Executive Summary\nThe request was approved.\n\n## Next Steps\n- **Owner:** Operations");

    Http::assertSent(function ($request): bool {
        $payload = $request->data();
        $userText = (string) data_get($payload, 'contents.0.parts.0.text');
        $decoded = json_decode($userText, true);
        $instructions = (string) ($decoded['instructions'] ?? '');

        return str_contains($instructions, 'Create one complete final summary')
            && str_contains($instructions, 'Use Markdown headings with ## or ###')
            && str_contains($instructions, 'Markdown bullets using -');
    });
});
