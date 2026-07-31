<?php

namespace App\Services;

class MistralTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_SMALL_2603 = 'mistral-small-2603';

    public function __construct(
        array $allowedModels,
        ?string $apiKey = null,
        ?string $model = null,
        ?string $endpoint = null,
        ?int $timeout = null,
        int $maxRetries,
    ) {
        parent::__construct(
            providerName: 'Mistral',
            allowedModels: $allowedModels,
            apiKey: $apiKey,
            model: $model,
            endpoint: $endpoint,
            timeout: $timeout,
            maxRetries: $maxRetries,
        );
    }
}
