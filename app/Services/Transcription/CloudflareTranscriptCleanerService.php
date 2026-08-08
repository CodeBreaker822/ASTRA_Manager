<?php

namespace App\Services\Transcription;

class CloudflareTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_GLM_4_7_FLASH = '@cf/zai-org/glm-4.7-flash';

    public function __construct(
        array $allowedModels,
        ?string $apiKey,
        ?string $model,
        ?string $endpoint,
        ?int $timeout,
        int $maxRetries,
    ) {
        parent::__construct(
            providerName: 'Cloudflare Workers AI',
            allowedModels: $allowedModels,
            apiKey: $apiKey,
            model: $model,
            endpoint: $endpoint,
            timeout: $timeout,
            maxRetries: $maxRetries,
        );
    }
}
