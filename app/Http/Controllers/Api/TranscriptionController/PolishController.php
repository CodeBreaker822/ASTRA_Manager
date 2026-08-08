<?php

namespace App\Http\Controllers\Api\TranscriptionController;

use App\Services\Transcription\AppSettingsService;
use App\Services\Transcription\CerebrasTranscriptCleanerService;
use App\Services\Transcription\CloudflareTranscriptCleanerService;
use App\Services\Transcription\DeepSeekTranscriptCleanerService;
use App\Services\Transcription\GeminiTranscriptCleanerService;
use App\Services\Transcription\GroqTranscriptCleanerService;
use App\Services\Transcription\MistralTranscriptCleanerService;
use App\Services\Transcription\NvidiaTranscriptCleanerService;
use App\Services\Transcription\OpenAICompatibleTranscriptCleanerService;
use App\Services\Transcription\OpenRouterTranscriptCleanerService;
use App\Services\Transcription\ProviderFallbackLogger;
use App\Services\Transcription\ServiceUserMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PolishController extends BaseTranscriptionApiController
{
    use InteractsWithRuntimeProviders;
    use SummarizesTranscripts;

    public function polish(Request $request, AppSettingsService $settings): JsonResponse
    {
        $startedAt = microtime(true);
        $license = $this->licenseFor($request, 'post');

        if ($license instanceof JsonResponse) {
            return $this->logAndReturn($request, 'polish', null, $license, $startedAt);
        }

        $rateLimited = $this->hitRateLimit($license);

        if ($rateLimited instanceof JsonResponse) {
            return $this->logAndReturn($request, 'polish', $license, $rateLimited, $startedAt, [
                'status' => 'rate_limited',
                'severity' => 'high',
            ]);
        }

        try {
            $validated = $request->validate([
                'provider' => ['nullable', 'string', 'max:100'],
                'model' => ['nullable', 'string', 'max:200'],
                'text' => ['nullable', 'string'],
                'timestamps' => ['nullable', 'array'],
                'chunks' => ['nullable', 'array'],
                'chunks.*' => ['array'],
                'chunks.*.audio_chunk_id' => ['nullable', 'integer'],
                'chunks.*.clip_index' => ['nullable', 'integer'],
                'chunks.*.range_label' => ['nullable', 'string', 'max:100'],
                'chunks.*.text' => ['nullable', 'string'],
                'chunks.*.timestamps' => ['nullable', 'array'],
                'instruction' => ['nullable', 'string', 'max:4000'],
                'task' => ['nullable', 'string', 'in:polish,summarize'],
            ]);
        } catch (ValidationException $exception) {
            return $this->logAndReturn($request, 'polish', $license, $this->validationError($exception), $startedAt);
        }

        if (blank($validated['text'] ?? null) && empty($validated['chunks'] ?? [])) {
            return $this->logAndReturn($request, 'polish', $license, response()->json([
                'message' => 'Provide text or transcript chunks to polish.',
            ], 422), $startedAt);
        }

        $providers = $settings->orderedConnectedProviders('text_fixer');
        $attemptedProviders = [];
        $result = null;
        $usedProvider = null;
        $hasChunks = ! empty($validated['chunks']);
        $normalizedChunks = $hasChunks ? $this->normalizeChunks($validated['chunks']) : [];
        $fallbackUsed = false;

        if (
            ! $hasChunks
            && count($providers) > 1
            && $this->isSummaryTask($validated['task'] ?? null, $validated['instruction'] ?? null)
            && mb_strlen((string) ($validated['text'] ?? '')) > $this->polishChunkCharacters()
        ) {
            try {
                $distributed = $this->summarizeAcrossProviders(
                    $providers,
                    (string) $validated['text'],
                    $validated['instruction'] ?? null,
                    $request,
                    $license,
                );
                $result = $distributed['result'];
                $usedProvider = $distributed['final_provider'];
                $attemptedProviders = $distributed['attempted_providers'];
                $fallbackUsed = $distributed['fallback_used'];
            } catch (Throwable $exception) {
                report($exception);
            }
        } else {
            foreach ($providers as $position => $provider) {
                $attemptedProviders[] = $provider['provider'];

                try {
                    $cleaner = $this->cleanerForProvider($provider);
                    $result = $this->polishUsingCleaner(
                        $cleaner,
                        $provider,
                        (string) ($validated['text'] ?? ''),
                        $validated['timestamps'] ?? [],
                        $normalizedChunks,
                        $validated['instruction'] ?? null,
                        $validated['task'] ?? null,
                    );
                    $usedProvider = $provider;
                    $fallbackUsed = $position > 0;
                    $operation = $this->isSummaryTask($validated['task'] ?? null, $validated['instruction'] ?? null)
                        ? 'summarize'
                        : 'polish';
                    app(ProviderFallbackLogger::class)->success('text_fixer', $operation, $provider, $position, $request, $license);
                    break;
                } catch (Throwable $exception) {
                    $operation = $this->isSummaryTask($validated['task'] ?? null, $validated['instruction'] ?? null)
                        ? 'summarize'
                        : 'polish';
                    app(ProviderFallbackLogger::class)->failure('text_fixer', $operation, $provider, $position, $exception, $request, $license);
                    report($exception);
                }
            }
        }

        if ($result === null || $usedProvider === null) {
            return $this->logAndReturn($request, 'polish', $license, response()->json([
                'message' => 'All configured text-fixer providers are unavailable.',
            ], 503), $startedAt, [
                'status' => 'provider_error',
                'severity' => 'high',
                'attempted_providers' => $attemptedProviders,
            ]);
        }

        $responseData = $hasChunks
            ? ['chunks' => $result['chunks']]
            : [
                'text' => $result['text'],
                'timestamps' => $result['timestamps'],
            ];

        $responseData = array_merge($responseData, [
            'provider' => AppSettingsService::PUBLIC_PROVIDER_ID,
            'provider_name' => AppSettingsService::PUBLIC_PROVIDER_NAME,
            'model' => AppSettingsService::PUBLIC_MODEL,
            'fallback' => ['used' => $fallbackUsed],
        ]);

        return $this->logAndReturn($request, 'polish', $license, response()->json($responseData), $startedAt, [
            'provider' => $usedProvider['provider'],
            'model' => $usedProvider['model'],
            'attempted_providers' => $attemptedProviders,
        ]);
    }

    private function normalizeChunks(array $chunks): array
    {
        return array_values(array_map(function (array $chunk, int $index): array {
            return [
                'id' => (int) ($chunk['audio_chunk_id'] ?? $chunk['clip_index'] ?? $index),
                'range_label' => $chunk['range_label'] ?? null,
                'text' => (string) ($chunk['text'] ?? ''),
                'timestamps' => array_values(array_filter($chunk['timestamps'] ?? [], 'is_array')),
            ];
        }, $chunks, array_keys($chunks)));
    }

    private function polishUsingCleaner(
        object $cleaner,
        array $provider,
        string $text,
        array $timestamps,
        array $chunks,
        ?string $instruction,
        ?string $task,
    ): array {
        if ($chunks !== []) {
            return $this->cleanChunkBatches($cleaner, $provider, $chunks, $instruction);
        }

        return $this->cleanLargeText($cleaner, $provider, $text, $timestamps, $instruction, $task);
    }

    private function cleanLargeText(
        object $cleaner,
        array $provider,
        string $text,
        array $timestamps,
        ?string $instruction,
        ?string $task,
    ): array {
        $parts = $this->splitTranscript($text);

        if (count($parts) === 1) {
            return $this->retryCleanerResponse(
                fn (): array => $cleaner->clean($text, $timestamps, ['instructions' => $instruction]),
                $provider,
                false,
            );
        }

        if ($this->isSummaryTask($task, $instruction)) {
            return $this->summarizeLargeTextWithCleaner($cleaner, $provider, $text, $instruction);
        }

        $cleanedParts = [];
        $model = null;

        foreach ($parts as $part) {
            $cleaned = $this->retryCleanerResponse(
                fn (): array => $cleaner->clean($part, [], ['instructions' => $instruction]),
                $provider,
                false,
            );
            $cleanedParts[] = $cleaned['text'];
            $model = $cleaned['model'] ?? $model;
        }

        return [
            'text' => implode("\n\n", $cleanedParts),
            'timestamps' => $timestamps,
            'model' => $model,
        ];
    }

    private function cleanChunkBatches(object $cleaner, array $provider, array $chunks, ?string $instruction): array
    {
        $batches = [];
        $batch = [];
        $batchCharacters = 0;
        $limit = $this->polishChunkCharacters();

        foreach ($chunks as $chunk) {
            $chunkCharacters = strlen((string) json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ($batch !== [] && $batchCharacters + $chunkCharacters > $limit) {
                $batches[] = $batch;
                $batch = [];
                $batchCharacters = 0;
            }

            $batch[] = $chunk;
            $batchCharacters += $chunkCharacters;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        $cleanedById = [];
        $model = null;

        foreach ($batches as $chunkBatch) {
            $result = $this->retryCleanerResponse(
                fn (): array => $cleaner->cleanChunks($chunkBatch, ['instructions' => $instruction]),
                $provider,
                true,
            );

            foreach ($result['chunks'] as $cleanedChunk) {
                $cleanedById[(int) $cleanedChunk['audio_chunk_id']] = $cleanedChunk;
            }

            $model = $result['model'] ?? $model;
        }

        return [
            'chunks' => array_map(
                fn (array $chunk): array => $cleanedById[(int) $chunk['id']],
                $chunks,
            ),
            'model' => $model,
        ];
    }

    private function retryCleanerResponse(callable $callback, array $provider, bool $expectsChunks): array
    {
        $attempts = max(1, (int) config('services.transcript_polishing.response_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $callback();
                $this->assertNonEmptyCleanerResult($result, $expectsChunks);

                return $result;
            } catch (Throwable $exception) {
                if (! $this->isRetryableCleanerResponse($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                Log::warning('Text fixer returned an unusable response; retrying the same provider.', [
                    'provider' => $provider['provider'],
                    'model' => $provider['model'],
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'failure_type' => $exception::class,
                ]);

                usleep(250000 * $attempt);
            }
        }

        throw new \RuntimeException(ServiceUserMessage::cleanerFailed());
    }

    private function assertNonEmptyCleanerResult(array $result, bool $expectsChunks): void
    {
        if (! $expectsChunks) {
            if (trim((string) ($result['text'] ?? '')) === '') {
                throw new \RuntimeException(ServiceUserMessage::emptyCleanerResponse('Text fixer'));
            }

            return;
        }

        $chunks = $result['chunks'] ?? null;

        if (! is_array($chunks) || $chunks === []) {
            throw new \RuntimeException(ServiceUserMessage::invalidCleanerResponse('Text fixer'));
        }

        foreach ($chunks as $chunk) {
            if (! is_array($chunk) || trim((string) ($chunk['text'] ?? '')) === '') {
                throw new \RuntimeException(ServiceUserMessage::emptyCleanerResponse('Text fixer'));
            }
        }
    }

    private function isRetryableCleanerResponse(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'empty polishing response')
            || str_contains($message, 'invalid polishing response')
            || str_contains($message, 'did not return every transcript chunk');
    }

    private function splitTranscript(string $text, ?int $maximumCharacters = null): array
    {
        $text = trim($text);
        $limit = $maximumCharacters ?? $this->polishChunkCharacters();

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $units = preg_split('/(?<=[.!?])\s+|\R{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $parts = [];
        $current = '';

        foreach ($units as $unit) {
            $unit = trim($unit);

            while (mb_strlen($unit) > $limit) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }

                $parts[] = trim(mb_substr($unit, 0, $limit));
                $unit = trim(mb_substr($unit, $limit));
            }

            if ($current !== '' && mb_strlen($current.' '.$unit) > $limit) {
                $parts[] = $current;
                $current = '';
            }

            $current = trim($current === '' ? $unit : $current.' '.$unit);
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function polishChunkCharacters(): int
    {
        return max(2000, (int) config('services.transcript_polishing.chunk_characters', 16000));
    }

    private function cleanerForProvider(array $provider): GeminiTranscriptCleanerService|GroqTranscriptCleanerService|DeepSeekTranscriptCleanerService|OpenAICompatibleTranscriptCleanerService
    {
        return match ($provider['provider']) {
            AppSettingsService::PROVIDER_GEMINI => new GeminiTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpointTemplate: $this->providerMetadataString($provider, 'endpoint_template'),
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => new GroqTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_DEEPSEEK => new DeepSeekTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_CEREBRAS => new CerebrasTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            AppSettingsService::PROVIDER_MISTRAL => new MistralTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            AppSettingsService::PROVIDER_OPENROUTER => new OpenRouterTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            AppSettingsService::PROVIDER_NVIDIA => new NvidiaTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            AppSettingsService::PROVIDER_CLOUDFLARE => new CloudflareTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
        };
    }
}
