<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MistralModelCatalogService
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
        return $this->modelIds(fn (array $model): bool => $this->isTranscriptionModel($model));
    }

    /**
     * @return array<int, string>
     */
    public function cleanerModelIds(): array
    {
        return $this->modelIds(fn (array $model): bool => $this->isCleanerModel($model));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchModels(): array
    {
        $apiKey = trim((string) ($this->apiKey ?? config('services.mistral.key')));
        $modelsUrl = trim((string) ($this->modelsUrl ?? config('services.mistral.models_url')));

        if ($apiKey === '' || $modelsUrl === '') {
            return [];
        }

        $cacheKey = 'mistral:models:'.hash('sha256', $modelsUrl.'|'.$apiKey);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($apiKey, $modelsUrl): array {
            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout($this->timeout ?? (int) config('services.mistral.timeout', 120))
                    ->get($modelsUrl);
            } catch (ConnectionException $exception) {
                Log::warning('Mistral model catalog could not be reached.', [
                    'models_url' => $modelsUrl,
                    'message' => $exception->getMessage(),
                ]);

                return [];
            }

            if ($response->failed()) {
                Log::warning('Mistral model catalog request failed.', [
                    'models_url' => $modelsUrl,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $payload = $response->json();
            $models = is_array($payload['data'] ?? null)
                ? $payload['data']
                : (is_array($payload) && array_is_list($payload) ? $payload : []);

            return array_values(array_filter($models, 'is_array'));
        });
    }

    /**
     * @param  callable(array<string, mixed>): bool  $filter
     * @return array<int, string>
     */
    private function modelIds(callable $filter): array
    {
        $ids = [];

        foreach ($this->fetchModels() as $model) {
            $id = trim((string) ($model['id'] ?? ''));

            if ($id !== '' && $filter($model)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Mistral does not currently publish a transcription capability flag for
     * every Voxtral model, so dedicated offline-transcription IDs are also
     * recognized while realtime and text-to-speech variants are excluded.
     *
     * @param  array<string, mixed>  $model
     */
    private function isTranscriptionModel(array $model): bool
    {
        if ($this->isArchived($model)) {
            return false;
        }

        $id = strtolower(trim((string) ($model['id'] ?? '')));

        if ($id === '' || str_contains($id, 'realtime') || str_contains($id, 'tts')) {
            return false;
        }

        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        $declaresTranscription = ($capabilities['transcription'] ?? false) === true
            || ($capabilities['audio_transcription'] ?? false) === true;

        return $declaresTranscription
            || preg_match('/^voxtral-mini-(?:transcribe-)?(?:latest|\d{4})$/', $id) === 1;
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function isCleanerModel(array $model): bool
    {
        if ($this->isArchived($model)) {
            return false;
        }

        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];

        return trim((string) ($model['id'] ?? '')) !== ''
            && ($capabilities['completion_chat'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function isArchived(array $model): bool
    {
        return filter_var($model['archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
