<?php

namespace App\Services\Transcription;

class NvidiaTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_NEMOTRON_3_NANO = 'nvidia/nemotron-3-nano-30b-a3b';

    /** @param  array<int, string>  $allowedModels */
    public function __construct(
        array $allowedModels = [],
        private readonly ?string $apiKey = null,
        ?string $model = null,
        ?string $endpoint = null,
        private readonly ?string $modelsUrl = null,
        private readonly ?int $timeout = null,
        int $maxRetries = 3,
    ) {
        $availableModels = $allowedModels !== [] ? $allowedModels : $this->getAvailableModelIds();

        parent::__construct(
            providerName: 'NVIDIA',
            allowedModels: $availableModels,
            apiKey: $apiKey,
            model: $model,
            endpoint: $endpoint,
            timeout: $timeout,
            maxRetries: $maxRetries,
        );
    }

    /** @return array<int, string> */
    public function getAvailableModelIds(): array
    {
        $apiKey = trim((string) (
            $this->apiKey
            ?? app(AppSettingsService::class)->nvidiaApiKey()
            ?? config('services.nvidia.key')
        ));
        $models = (new NvidiaModelCatalogService(
            apiKey: $apiKey,
            modelsUrl: $this->modelsUrl,
            timeout: $this->timeout,
        ))->cleanerModelIds();

        return $models !== []
            ? $models
            : array_values(array_filter((array) config('services.nvidia.text_fixer_models', []), 'is_string'));
    }
}
