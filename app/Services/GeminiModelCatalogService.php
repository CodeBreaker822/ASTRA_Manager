<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiModelCatalogService
{
    private const CACHE_TTL_SECONDS = 3600;

    private const PAGE_SIZE = 1000;

    private const MAX_PAGES = 20;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $modelsUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function cleanerModelIds(): array
    {
        $ids = [];

        foreach ($this->fetchModels() as $model) {
            if (! $this->isTextCleanerModel($model)) {
                continue;
            }

            $id = $this->modelId($model);

            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchModels(): array
    {
        $apiKey = trim((string) ($this->apiKey ?? config('services.gemini.key')));
        $modelsUrl = trim((string) ($this->modelsUrl ?? config('services.gemini.models_url')));

        if ($apiKey === '' || $modelsUrl === '') {
            return [];
        }

        $cacheKey = 'gemini:models:'.hash('sha256', $modelsUrl.'|'.$apiKey);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($apiKey, $modelsUrl): array {
            $models = [];
            $pageToken = null;
            $seenPageTokens = [];

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $query = [
                    'key' => $apiKey,
                    'pageSize' => self::PAGE_SIZE,
                ];

                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }

                try {
                    $response = Http::acceptJson()
                        ->timeout($this->timeout ?? (int) config('services.gemini.timeout', 120))
                        ->get($modelsUrl, $query);
                } catch (ConnectionException $exception) {
                    Log::warning('Gemini model catalog could not be reached.', [
                        'models_url' => $modelsUrl,
                        'message' => $exception->getMessage(),
                    ]);

                    return [];
                }

                if ($response->failed()) {
                    Log::warning('Gemini model catalog request failed.', [
                        'models_url' => $modelsUrl,
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                $payload = $response->json();
                $pageModels = is_array($payload['models'] ?? null) ? $payload['models'] : [];

                foreach ($pageModels as $model) {
                    if (is_array($model)) {
                        $models[] = $model;
                    }
                }

                $nextPageToken = trim((string) ($payload['nextPageToken'] ?? ''));

                if ($nextPageToken === '' || isset($seenPageTokens[$nextPageToken])) {
                    break;
                }

                $seenPageTokens[$nextPageToken] = true;
                $pageToken = $nextPageToken;
            }

            return $models;
        });
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function isTextCleanerModel(array $model): bool
    {
        $id = strtolower($this->modelId($model));

        if ($id === '' || ! str_starts_with($id, 'gemini-')) {
            return false;
        }

        $methods = array_map(
            fn (mixed $method): string => strtolower(trim((string) $method)),
            is_array($model['supportedGenerationMethods'] ?? null)
                ? $model['supportedGenerationMethods']
                : [],
        );

        if (! in_array('generatecontent', $methods, true)) {
            return false;
        }

        $specializedModelFragments = [
            'embedding',
            'image',
            'imagen',
            'veo',
            'tts',
            'live',
            'native-audio',
            'audio-dialog',
            'robotics',
            'omni',
        ];

        foreach ($specializedModelFragments as $fragment) {
            if (str_contains($id, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function modelId(array $model): string
    {
        $name = trim((string) ($model['name'] ?? ''));

        return str_starts_with($name, 'models/') ? substr($name, 7) : $name;
    }
}
