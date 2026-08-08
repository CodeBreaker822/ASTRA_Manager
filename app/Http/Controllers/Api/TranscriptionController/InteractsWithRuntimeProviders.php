<?php

namespace App\Http\Controllers\Api\TranscriptionController;

trait InteractsWithRuntimeProviders
{
    /**
     * @param  array<string, mixed>  $provider
     */
    protected function providerMetadataString(array $provider, string $key): string
    {
        $value = ($provider['metadata'] ?? [])[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Provider [{$provider['provider']}] runtime setting [{$key}] is not configured in API Manager.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    protected function providerMetadataInt(array $provider, string $key): int
    {
        $value = ($provider['metadata'] ?? [])[$key] ?? null;

        if (! is_numeric($value)) {
            throw new \RuntimeException("Provider [{$provider['provider']}] runtime setting [{$key}] is not configured in API Manager.");
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<int, string>
     */
    protected function providerModels(array $provider): array
    {
        $models = $provider['models'] ?? null;

        if (! is_array($models) || array_values(array_filter($models, is_string(...))) === []) {
            throw new \RuntimeException("Provider [{$provider['provider']}] model list is not configured in API Manager.");
        }

        return array_values(array_filter($models, is_string(...)));
    }

    protected function fallbackDetails(array $attemptedProviders): array
    {
        return [
            'used' => count($attemptedProviders) > 1,
        ];
    }
}
