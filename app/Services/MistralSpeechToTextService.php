<?php

namespace App\Services;

use App\Exceptions\MistralSpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class MistralSpeechToTextService
{
    public const MODEL_VOXTRAL_MINI_LATEST = 'voxtral-mini-latest';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $endpoint = null,
        private readonly ?string $modelId = null,
        private readonly ?string $modelsUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->resolveAudioFile($audio);
        $stream = fopen($file['path'], 'rb');

        if ($stream === false) {
            throw new MistralSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->client()
                ->attach('file', $stream, $file['name'])
                ->post($this->endpoint(), $this->payload($options));
        } catch (ConnectionException $exception) {
            throw new MistralSpeechToTextException(
                ServiceUserMessage::cannotReachProvider('Mistral'),
                0,
                $exception,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->failed()) {
            Log::error('Mistral transcription request failed.', [
                'status' => $response->status(),
                'file_name' => $file['name'],
                'file_size_bytes' => is_file($file['path']) ? filesize($file['path']) : null,
                'response' => $response->json() ?? $response->body(),
            ]);

            throw new MistralSpeechToTextException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status(),
            );
        }

        return $this->normalizeTranscript($response->json() ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function getAvailableModelIds(): array
    {
        $models = (new MistralModelCatalogService(
            apiKey: $this->resolvedApiKey(false),
            modelsUrl: $this->modelsUrl,
            timeout: $this->timeout,
        ))->transcriptionModelIds();

        return $models !== []
            ? $models
            : array_values(array_filter((array) config('services.mistral.transcription_models', []), 'is_string'));
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->resolvedApiKey())
            ->acceptJson()
            ->timeout($this->timeout ?? (int) config('services.mistral.timeout', 120));
    }

    private function resolvedApiKey(bool $required = true): string
    {
        $apiKey = trim((string) (
            $this->apiKey
            ?? app(AppSettingsService::class)->mistralTranscriptionApiKey()
            ?? config('services.mistral.key')
        ));

        if ($required && $apiKey === '') {
            throw new MistralSpeechToTextException(ServiceUserMessage::missingApiKey('Mistral'));
        }

        return $apiKey;
    }

    private function endpoint(): string
    {
        return $this->endpoint ?? (string) config('services.mistral.transcription_url');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function payload(array $options): array
    {
        $payload = [
            'model' => $this->resolveModelId(),
            'diarize' => 'true',
            'timestamp_granularities[]' => 'word',
        ];
        $language = $this->normalizeLanguageCode($options['language_code'] ?? null);

        if ($language !== null) {
            $payload['language'] = $language;
        }

        return $payload;
    }

    private function resolveModelId(): string
    {
        $selected = trim((string) (
            $this->modelId
            ?? app(AppSettingsService::class)->mistralTranscriptionModel()
        ));
        $available = $this->getAvailableModelIds();

        if ($selected !== '' && in_array($selected, $available, true)) {
            return $selected;
        }

        return $available[0] ?? self::MODEL_VOXTRAL_MINI_LATEST;
    }

    private function normalizeLanguageCode(mixed $languageCode): ?string
    {
        $language = strtolower(trim((string) $languageCode));

        return match ($language) {
            '', 'auto', 'multi', 'multilingual' => null,
            'fil', 'tgl', 'tagalog' => 'tl',
            default => $language,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalizeTranscript(array $response): array
    {
        $segments = is_array($response['segments'] ?? null)
            ? array_values(array_filter($response['segments'], 'is_array'))
            : [];

        return [
            'text' => (string) ($response['text'] ?? ''),
            'timestamps' => array_map(
                fn (array $segment): array => [
                    'text' => trim((string) ($segment['text'] ?? $segment['word'] ?? '')),
                    'start' => $segment['start'] ?? null,
                    'end' => $segment['end'] ?? null,
                    'type' => isset($segment['word']) ? 'word' : 'segment',
                    'speaker_id' => $segment['speaker_id'] ?? $segment['speaker'] ?? null,
                ],
                $segments,
            ),
        ];
    }

    /**
     * @return array{path: string, name: string}
     */
    private function resolveAudioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getRealPath();
            $name = $audio->getClientOriginalName() ?: $audio->getFilename();
        } elseif ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();
            $name = $audio->getFilename();
        } else {
            $path = $audio;
            $name = basename($audio);
        }

        if (! is_string($path) || ! is_file($path)) {
            throw new MistralSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return ['path' => $path, 'name' => $name];
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('Mistral'),
            $status === 413 => 'Mistral could not accept the audio because the file is too large.',
            $status === 429 => ServiceUserMessage::providerBusy('Mistral'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('Mistral'),
            in_array($status, [400, 404, 422], true) => 'Mistral could not accept the audio, model, or language setting.',
            default => ServiceUserMessage::transcriptionFailed('Mistral'),
        };
    }
}
