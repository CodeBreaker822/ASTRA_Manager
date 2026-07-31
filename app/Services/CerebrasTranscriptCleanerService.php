<?php

namespace App\Services;

class CerebrasTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_GPT_OSS_120B = 'gpt-oss-120b';

    public function __construct(
        array $allowedModels,
        ?string $apiKey = null,
        ?string $model = null,
        ?string $endpoint = null,
        ?int $timeout = null,
        int $maxRetries,
    ) {
        parent::__construct(
            providerName: 'Cerebras',
            allowedModels: $allowedModels,
            apiKey: $apiKey,
            model: $model,
            endpoint: $endpoint,
            timeout: $timeout,
            maxRetries: $maxRetries,
            extraPayload: ['reasoning_effort' => 'low'],
        );
    }
}
