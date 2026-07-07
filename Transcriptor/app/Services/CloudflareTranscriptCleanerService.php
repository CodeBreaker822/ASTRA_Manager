<?php

namespace App\Services;

class CloudflareTranscriptCleanerService extends OpenAICompatibleTranscriptCleanerService
{
    public const MODEL_GLM_4_7_FLASH = '@cf/zai-org/glm-4.7-flash';

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $endpoint = null,
        ?int $timeout = null,
    ) {
        parent::__construct(
            providerName: 'Cloudflare Workers AI',
            allowedModels: config('services.cloudflare.models', [self::MODEL_GLM_4_7_FLASH]),
            apiKey: $apiKey ?? app(AppSettingsService::class)->cloudflareApiKey() ?? config('services.cloudflare.key'),
            model: $model ?? app(AppSettingsService::class)->cloudflareModel(),
            endpoint: $endpoint ?? app(AppSettingsService::class)->cloudflareChatCompletionsUrl(),
            timeout: $timeout ?? app(AppSettingsService::class)->cloudflareTimeout(),
            maxRetries: app(AppSettingsService::class)->cloudflareMaxRetries(),
        );
    }
}
