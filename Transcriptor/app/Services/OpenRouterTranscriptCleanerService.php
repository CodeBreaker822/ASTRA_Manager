<?php

namespace App\Services;

class OpenRouterTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_GEMMA_3_12B_FREE = 'google/gemma-3-12b-it:free';

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $endpoint = null,
        ?int $timeout = null,
    ) {
        parent::__construct(
            providerName: 'OpenRouter',
            allowedModels: config('services.openrouter.models', [self::MODEL_GEMMA_3_12B_FREE]),
            apiKey: $apiKey ?? app(AppSettingsService::class)->openRouterApiKey() ?? config('services.openrouter.key'),
            model: $model ?? app(AppSettingsService::class)->openRouterModel(),
            endpoint: $endpoint ?? config('services.openrouter.chat_completions_url'),
            timeout: $timeout ?? app(AppSettingsService::class)->openRouterTimeout(),
            maxRetries: app(AppSettingsService::class)->openRouterMaxRetries(),
        );
    }
}
