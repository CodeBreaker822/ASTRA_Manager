<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAICompatibleTranscriptCleanerService
{
    public function __construct(
        private readonly string $providerName,
        private readonly array $allowedModels,
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?string $endpoint = null,
        private readonly ?int $timeout = null,
        private readonly int $maxRetries = 3,
        private readonly array $extraPayload = [],
    ) {}

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, model: string}
     */
    public function clean(string $text, array $timestamps = [], array $options = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return [
                'text' => '',
                'timestamps' => [],
                'model' => $this->getModel(),
            ];
        }

        $response = $this->postChatCompletion($this->payload($text, $timestamps, $options));
        $this->assertResponseCompleted($response);
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException(ServiceUserMessage::emptyCleanerResponse($this->providerName));
        }

        return $this->normalizeCleanedTranscript($content, $timestamps);
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, model: string}
     */
    public function cleanChunks(array $chunks, array $options = []): array
    {
        $chunks = array_values(array_map(
            fn (array $chunk): array => [
                'id' => (int) $chunk['id'],
                'range_label' => $chunk['range_label'] ?? null,
                'text' => trim((string) ($chunk['text'] ?? '')),
                'timestamps' => array_values(array_filter($chunk['timestamps'] ?? [], 'is_array')),
            ],
            $chunks,
        ));

        if ($chunks === [] || collect($chunks)->every(fn (array $chunk): bool => $chunk['text'] === '')) {
            return [
                'chunks' => array_map(
                    fn (array $chunk): array => [
                        'audio_chunk_id' => $chunk['id'],
                        'text' => '',
                        'timestamps' => [],
                    ],
                    $chunks,
                ),
                'model' => $this->getModel(),
            ];
        }

        $response = $this->postChatCompletion($this->chunkPayload($chunks, $options));
        $this->assertResponseCompleted($response);
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException(ServiceUserMessage::emptyCleanerResponse($this->providerName));
        }

        return $this->normalizeCleanedChunks($content, $chunks);
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->getApiKey())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout ?? 120);
    }

    private function getEndpoint(): string
    {
        return (string) $this->endpoint;
    }

    private function getModel(): string
    {
        $model = $this->model;

        if (! is_string($model) || ! in_array($model, $this->allowedModels, true)) {
            throw new RuntimeException(ServiceUserMessage::unsupportedProviderModel($this->providerName));
        }

        return $model;
    }

    private function getApiKey(): string
    {
        $apiKey = $this->apiKey;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException(ServiceUserMessage::missingApiKey($this->providerName));
        }

        return trim($apiKey);
    }

    private function postChatCompletion(array $payload): Response
    {
        $startedAt = microtime(true);
        $logContext = [
            'model' => $this->getModel(),
            'endpoint' => $this->getEndpoint(),
            'request_character_count' => strlen((string) ($payload['messages'][1]['content'] ?? '')),
        ];

        Log::info($this->providerName.' transcript cleaner request started.', $logContext);

        try {
            $response = $this->client()
                ->retry(
                    $this->maxRetries,
                    1000,
                    fn ($exception): bool => $this->shouldRetryRequest($exception),
                    throw: false,
                )
                ->post($this->getEndpoint(), $payload);
        } catch (ConnectionException $exception) {
            Log::error($this->providerName.' transcript cleaner request timed out.', array_merge($logContext, [
                'elapsed_ms' => $this->elapsedMs($startedAt),
                'error' => $exception->getMessage(),
            ]));

            throw new RuntimeException(
                ServiceUserMessage::cannotReachProvider($this->providerName),
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            Log::error($this->providerName.' transcript cleaner request failed.', array_merge($logContext, [
                'elapsed_ms' => $this->elapsedMs($startedAt),
                'status' => $response->status(),
                'response_body' => $response->json() ?? $response->body(),
            ]));

            throw new RuntimeException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status(),
            );
        }

        return $response;
    }

    private function shouldRetryRequest(mixed $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        $response = $exception->response ?? null;

        if (! $response instanceof Response) {
            return false;
        }

        return in_array($response->status(), [408, 425], true)
            || $response->status() >= 500
            || ($response->status() === 429 && ! $this->isPermanentQuotaFailure($response));
    }

    private function isPermanentQuotaFailure(Response $response): bool
    {
        $body = strtolower($response->body());

        return str_contains($body, 'insufficient_quota')
            || str_contains($body, 'quota exhausted')
            || str_contains($body, 'insufficient credit')
            || str_contains($body, 'credits exhausted')
            || str_contains($body, 'insufficient balance')
            || str_contains($body, 'insufficient funds')
            || str_contains($body, 'payment required')
            || str_contains($body, 'billing');
    }

    private function assertResponseCompleted(Response $response): void
    {
        $finishReason = strtolower((string) $response->json('choices.0.finish_reason'));

        if (in_array($finishReason, ['length', 'max_tokens', 'max_output_tokens'], true)) {
            throw new RuntimeException(ServiceUserMessage::invalidCleanerResponse($this->providerName));
        }
    }

    private function payload(string $text, array $timestamps, array $options): array
    {
        return $this->chatPayload(
            $this->systemInstruction([
                'Return JSON only with keys text and timestamps while preserving the timestamp schema.',
            ], $options),
            [
                'raw_text' => $text,
                'timestamps' => $timestamps,
                'instructions' => $options['instructions'] ?? null,
                'timestamp_schema' => [
                    'text' => 'word or cleaned segment text',
                    'start' => 'original start time when available',
                    'end' => 'original end time when available',
                    'type' => 'original token type when available',
                    'speaker_id' => 'original speaker id when available',
                ],
            ],
            $options,
        );
    }

    private function chunkPayload(array $chunks, array $options): array
    {
        return $this->chatPayload(
            $this->systemInstruction([
                'Clean each chunk independently and keep the same audio_chunk_id values.',
                'Do not merge chunks, split chunks, or change timestamps except removing words that were removed from the transcript.',
                'Return JSON only with one key: chunks.',
            ], $options),
            [
                'chunks' => array_map(
                    fn (array $chunk): array => [
                        'audio_chunk_id' => $chunk['id'],
                        'range_label' => $chunk['range_label'],
                        'raw_text' => $chunk['text'],
                        'timestamps' => $chunk['timestamps'],
                    ],
                    $chunks,
                ),
                'instructions' => $options['instructions'] ?? null,
                'response_schema' => [
                    'chunks' => [
                        [
                            'audio_chunk_id' => 'same id from request',
                            'text' => 'cleaned transcript text for this chunk only',
                            'timestamps' => [
                                [
                                    'text' => 'word or cleaned segment text',
                                    'start' => 'original start time when available',
                                    'end' => 'original end time when available',
                                    'type' => 'original token type when available',
                                    'speaker_id' => 'original speaker id when available',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $options,
        );
    }

    private function chatPayload(string $systemInstruction, array $userPayload, array $options): array
    {
        $payload = [
            'model' => $this->getModel(),
            'messages' => [
                ['role' => 'system', 'content' => $systemInstruction],
                [
                    'role' => 'user',
                    'content' => json_encode($userPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ],
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => (int) config('services.transcript_polishing.max_output_tokens', 4096),
            'response_format' => ['type' => 'json_object'],
        ];

        return array_merge($payload, $this->extraPayload);
    }

    private function systemInstruction(array $rules, array $options): string
    {
        $instructions = trim((string) ($options['instructions'] ?? ''));
        $parts = [
            'Follow the user-provided transcript polishing instructions exactly.',
            'If the user asks for English translation, translate Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switched speech into clear English instead of leaving source-language words behind.',
            'Only preserve source-language words when they are names, offices, agencies, titles, acronyms, places, or proper nouns.',
            'Preserve the speaker meaning, numbers, time order, and audio_chunk_id values.',
            ...$rules,
        ];

        if ($instructions !== '') {
            $parts[] = 'User-provided transcript polishing instructions: '.$instructions;
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, model: string}
     */
    private function normalizeCleanedTranscript(string $content, array $fallbackTimestamps): array
    {
        $decoded = json_decode($this->stripJsonFence($content), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(ServiceUserMessage::invalidCleanerResponse($this->providerName));
        }

        $text = trim((string) ($decoded['text'] ?? ''));

        if ($text === '') {
            throw new RuntimeException(ServiceUserMessage::emptyCleanerResponse($this->providerName));
        }

        $timestamps = is_array($decoded['timestamps'] ?? null)
            ? array_values(array_filter($decoded['timestamps'], 'is_array'))
            : $fallbackTimestamps;

        return [
            'text' => $text,
            'timestamps' => array_map(
                fn (array $item): array => [
                    'text' => (string) ($item['text'] ?? ''),
                    'start' => $item['start'] ?? null,
                    'end' => $item['end'] ?? null,
                    'type' => $item['type'] ?? null,
                    'speaker_id' => $item['speaker_id'] ?? null,
                ],
                $timestamps,
            ),
            'model' => $this->getModel(),
        ];
    }

    /**
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, model: string}
     */
    private function normalizeCleanedChunks(string $content, array $sourceChunks): array
    {
        $decoded = json_decode($this->stripJsonFence($content), true);

        if (! is_array($decoded) || ! is_array($decoded['chunks'] ?? null)) {
            throw new RuntimeException(ServiceUserMessage::invalidCleanerResponse($this->providerName));
        }

        $sourceIds = array_flip(array_map(fn (array $chunk): int => (int) $chunk['id'], $sourceChunks));
        $cleaned = [];

        foreach ($decoded['chunks'] as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $audioChunkId = (int) ($chunk['audio_chunk_id'] ?? 0);

            if (! isset($sourceIds[$audioChunkId])) {
                continue;
            }

            $timestamps = is_array($chunk['timestamps'] ?? null)
                ? array_values(array_filter($chunk['timestamps'], 'is_array'))
                : [];

            $cleaned[$audioChunkId] = [
                'audio_chunk_id' => $audioChunkId,
                'text' => (string) ($chunk['text'] ?? $chunk['raw_text'] ?? ''),
                'timestamps' => array_map(
                    fn (array $item): array => [
                        'text' => (string) ($item['text'] ?? ''),
                        'start' => $item['start'] ?? null,
                        'end' => $item['end'] ?? null,
                        'type' => $item['type'] ?? null,
                        'speaker_id' => $item['speaker_id'] ?? null,
                    ],
                    $timestamps,
                ),
            ];
        }

        foreach ($sourceChunks as $sourceChunk) {
            $sourceId = (int) $sourceChunk['id'];

            if (! isset($cleaned[$sourceId])) {
                throw new RuntimeException(ServiceUserMessage::cleanerMissingChunks($this->providerName));
            }

            if (trim((string) $sourceChunk['text']) !== '' && trim($cleaned[$sourceId]['text']) === '') {
                throw new RuntimeException(ServiceUserMessage::emptyCleanerResponse($this->providerName));
            }
        }

        return [
            'chunks' => array_values($cleaned),
            'model' => $this->getModel(),
        ];
    }

    private function stripJsonFence(string $content): string
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        return trim($content);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey($this->providerName),
            $status === 429 => ServiceUserMessage::providerBusy($this->providerName),
            $status >= 500 => ServiceUserMessage::providerUnavailable($this->providerName),
            default => ServiceUserMessage::cleanerFailed($this->providerName),
        };
    }
}
