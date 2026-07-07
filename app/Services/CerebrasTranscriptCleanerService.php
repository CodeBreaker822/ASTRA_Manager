<?php

namespace App\Services;

class CerebrasTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_GPT_OSS_120B = 'gpt-oss-120b';

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $endpoint = null,
        ?int $timeout = null,
    ) {
        parent::__construct(
            providerName: 'Cerebras',
            allowedModels: config('services.cerebras.models', [self::MODEL_GPT_OSS_120B]),
            apiKey: $apiKey ?? app(AppSettingsService::class)->cerebrasApiKey() ?? config('services.cerebras.key'),
            model: $model ?? app(AppSettingsService::class)->cerebrasModel(),
            endpoint: $endpoint ?? config('services.cerebras.chat_completions_url'),
            timeout: $timeout ?? app(AppSettingsService::class)->cerebrasTimeout(),
            maxRetries: app(AppSettingsService::class)->cerebrasMaxRetries(),
            extraPayload: ['reasoning_effort' => 'low'],
        );
    }
}
