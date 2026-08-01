<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqModelCatalogService
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $modelsUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function transcriptionModelIds(): array
    {
        return $this->modelIds(fn (string $id): bool => str_contains(strtolower($id), 'whisper'));
    }

    /**
     * @return array<int, string>
     */
    public function cleanerModelIds(): array
    {
        return $this->modelIds(function (string $id): bool {
            $id = strtolower($id);

            foreach ([
                'whisper',
                'safeguard',
                'prompt-guard',
                'moderation',
                'embedding',
                'embed',
                'orpheus',
                'playai-tts',
                'text-to-speech',
            ] as $unsupportedFragment) {
                if (str_contains($id, $unsupportedFragment)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchModels(): array
    {
        $apiKey = trim((string) ($this->apiKey ?? config('services.groq.key')));
        $modelsUrl = trim((string) ($this->modelsUrl ?? config('services.groq.models_url')));

        if ($apiKey === '' || $modelsUrl === '') {
            return [];
        }

        $cacheKey = $this->cacheKey($apiKey, $modelsUrl);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($apiKey, $modelsUrl): array {
            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout($this->timeout ?? (int) config('services.groq.timeout', 120))
                    ->get($modelsUrl);
            } catch (ConnectionException $exception) {
                Log::warning('Groq model catalog could not be reached.', [
                    'models_url' => $modelsUrl,
                    'message' => $exception->getMessage(),
                ]);

                return [];
            }

            if ($response->failed()) {
                Log::warning('Groq model catalog request failed.', [
                    'models_url' => $modelsUrl,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $models = $response->json('data', []);

            return is_array($models) ? array_values(array_filter($models, 'is_array')) : [];
        });
    }

    public function clear(): void
    {
        $apiKey = trim((string) ($this->apiKey ?? config('services.groq.key')));
        $modelsUrl = trim((string) ($this->modelsUrl ?? config('services.groq.models_url')));

        if ($apiKey !== '' && $modelsUrl !== '') {
            Cache::forget($this->cacheKey($apiKey, $modelsUrl));
        }
    }

    /**
     * @param  callable(string): bool  $filter
     * @return array<int, string>
     */
    private function modelIds(callable $filter): array
    {
        $ids = [];

        foreach ($this->fetchModels() as $model) {
            $id = trim((string) ($model['id'] ?? ''));
            $active = filter_var($model['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if ($id !== '' && $active && $filter($id)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function cacheKey(string $apiKey, string $modelsUrl): string
    {
        return 'groq:models:'.hash('sha256', $modelsUrl.'|'.$apiKey);
    }
}
