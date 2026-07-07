<?php

namespace App\Services;

class MistralTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_SMALL_2603 = 'mistral-small-2603';

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $endpoint = null,
        ?int $timeout = null,
    ) {
        parent::__construct(
            providerName: 'Mistral',
            allowedModels: config('services.mistral.models', [self::MODEL_SMALL_2603]),
            apiKey: $apiKey ?? app(AppSettingsService::class)->mistralApiKey() ?? config('services.mistral.key'),
            model: $model ?? app(AppSettingsService::class)->mistralModel(),
            endpoint: $endpoint ?? config('services.mistral.chat_completions_url'),
            timeout: $timeout ?? app(AppSettingsService::class)->mistralTimeout(),
            maxRetries: app(AppSettingsService::class)->mistralMaxRetries(),
        );
    }
}
