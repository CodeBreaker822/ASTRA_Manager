<?php

namespace App\Services\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NvidiaModelCatalogService
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * NVIDIA's model API includes task-specific models that do not provide a
     * general chat-completions surface suitable for transcript cleanup.
     */
    private const CLEANER_EXCLUDED_FRAGMENTS = [
        'asr',
        'audio',
        'bge-',
        'calibration',
        'canary',
        'clip',
        'content-safety',
        'cosmos',
        'deplot',
        'diffusion',
        'detection',
        'embed',
        'embedding',
        'flux',
        'fuyu',
        'gliner',
        'guard',
        'grounding',
        'image',
        'jailbreak',
        'kosmos',
        'moderation',
        'multimodal',
        'ocr',
        'omni',
        'parakeet',
        'parse',
        'pii',
        'rerank',
        'reward',
        'retriever',
        'safety',
        'speech',
        'stable-video',
        'text-to-speech',
        'topic-control',
        'transcription',
        'tts',
        'video',
        'vila',
        'visual',
        'vision',
        '-vl',
        'vlm',
        'whisper',
        '/neva-',
    ];

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $modelsUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    /**
     * NVIDIA does not currently expose a hosted speech-to-text endpoint that
     * this application can call, so no catalog model is classified as a
     * transcriber even if a future model ID contains speech-related wording.
     *
     * @return array<int, string>
     */
    public function transcriptionModelIds(): array
    {
        return [];
    }

    /** @return array<int, string> */
    public function cleanerModelIds(): array
    {
        return $this->modelIds(fn (array $model): bool => $this->isCleanerModel($model));
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchModels(): array
    {
        $apiKey = trim((string) ($this->apiKey ?? config('services.nvidia.key')));
        $modelsUrl = trim((string) ($this->modelsUrl ?? config('services.nvidia.models_url')));

        if ($apiKey === '' || $modelsUrl === '') {
            return [];
        }

        return Cache::remember(
            $this->cacheKey($apiKey, $modelsUrl),
            self::CACHE_TTL_SECONDS,
            function () use ($apiKey, $modelsUrl): array {
                try {
                    $response = Http::withToken($apiKey)
                        ->acceptJson()
                        ->timeout($this->timeout ?? (int) config('services.nvidia.timeout', 120))
                        ->get($modelsUrl);
                } catch (ConnectionException $exception) {
                    Log::warning('NVIDIA model catalog could not be reached.', [
                        'models_url' => $modelsUrl,
                        'message' => $exception->getMessage(),
                    ]);

                    return [];
                }

                if ($response->failed()) {
                    Log::warning('NVIDIA model catalog request failed.', [
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
            },
        );
    }

    public function clear(): void
    {
        $apiKey = trim((string) ($this->apiKey ?? config('services.nvidia.key')));
        $modelsUrl = trim((string) ($this->modelsUrl ?? config('services.nvidia.models_url')));

        if ($apiKey !== '' && $modelsUrl !== '') {
            Cache::forget($this->cacheKey($apiKey, $modelsUrl));
        }
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

    /** @param  array<string, mixed>  $model */
    private function isCleanerModel(array $model): bool
    {
        $id = strtolower(trim((string) ($model['id'] ?? '')));

        if ($id === '' || $this->isInactive($model)) {
            return false;
        }

        $description = strtolower(implode(' ', array_filter([
            $id,
            is_string($model['owned_by'] ?? null) ? $model['owned_by'] : null,
            is_string($model['type'] ?? null) ? $model['type'] : null,
            is_string($model['task'] ?? null) ? $model['task'] : null,
            is_string($model['category'] ?? null) ? $model['category'] : null,
        ])));

        foreach (self::CLEANER_EXCLUDED_FRAGMENTS as $fragment) {
            if (str_contains($description, $fragment)) {
                return false;
            }
        }

        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        $declaredChatCapabilities = array_intersect_key($capabilities, array_flip([
            'chat',
            'chat_completion',
            'chat_completions',
            'completion_chat',
            'text_generation',
        ]));

        return $declaredChatCapabilities === []
            || collect($declaredChatCapabilities)->contains(
                fn (mixed $enabled): bool => filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            );
    }

    /** @param  array<string, mixed>  $model */
    private function isInactive(array $model): bool
    {
        if (array_key_exists('active', $model)
            && ! filter_var($model['active'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return in_array(strtolower((string) ($model['status'] ?? '')), [
            'archived',
            'disabled',
            'inactive',
            'unavailable',
        ], true);
    }

    private function cacheKey(string $apiKey, string $modelsUrl): string
    {
        return 'nvidia:models:'.hash('sha256', $modelsUrl.'|'.$apiKey);
    }
}
