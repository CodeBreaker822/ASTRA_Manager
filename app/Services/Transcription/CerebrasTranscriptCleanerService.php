<?php

namespace App\Services\Transcription;

class CerebrasTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_GPT_OSS_120B = 'gpt-oss-120b';

    public function __construct(
        array $allowedModels,
        ?string $apiKey,
        ?string $model,
        ?string $endpoint,
        ?int $timeout,
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
