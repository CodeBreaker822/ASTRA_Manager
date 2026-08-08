<?php

namespace App\Services\Transcription;

class OpenRouterTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_GEMMA_3_12B_FREE = 'google/gemma-3-12b-it:free';

    public function __construct(
        array $allowedModels,
        ?string $apiKey,
        ?string $model,
        ?string $endpoint,
        ?int $timeout,
        int $maxRetries,
    ) {
        parent::__construct(
            providerName: 'OpenRouter',
            allowedModels: $allowedModels,
            apiKey: $apiKey,
            model: $model,
            endpoint: $endpoint,
            timeout: $timeout,
            maxRetries: $maxRetries,
        );
    }
}
