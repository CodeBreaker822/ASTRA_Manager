<?php

namespace App\Services;

use App\Exceptions\GroqSpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class GroqSpeechToTextService
{
    public const MODEL_WHISPER_LARGE_V3 = 'whisper-large-v3';

    public const MODEL_WHISPER_LARGE_V3_TURBO = 'whisper-large-v3-turbo';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $endpoint = null,
        private readonly ?string $modelId = null,
        private readonly ?int $timeout = null,
    ) {}

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->resolveAudioFile($audio);
        $stream = fopen($file['path'], 'rb');

        if ($stream === false) {
            throw new GroqSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->client()
                ->attach('file', $stream, $file['name'])
                ->post($this->getEndpoint(), $this->payload($options));
        } catch (ConnectionException $exception) {
            throw new GroqSpeechToTextException(
                ServiceUserMessage::cannotReachProvider('Groq'),
                0,
                $exception,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->failed()) {
            Log::error('Groq transcription request failed.', $this->responseLogContext($response, $file));

            throw new GroqSpeechToTextException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status(),
            );
        }

        return $this->normalizeTranscript($response->json() ?? []);
    }

    /**
     * @return array{path: string, name: string}
     */
    private function resolveAudioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new GroqSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new GroqSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
            ];
        }

        if (! is_file($audio)) {
            throw new GroqSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
        ];
    }

    private function client(): PendingRequest
    {
        $apiKey = $this->apiKey
            ?? app(AppSettingsService::class)->groqTranscriptionApiKey()
            ?? config('services.groq.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new GroqSpeechToTextException(ServiceUserMessage::missingApiKey('Groq'));
        }

        return Http::withToken(trim($apiKey))
            ->acceptJson()
            ->timeout($this->timeout ?? app(AppSettingsService::class)->groqTimeout());
    }

    private function getEndpoint(): string
    {
        return $this->endpoint ?? (string) config('services.groq.transcription_url');
    }

    private function payload(array $options): array
    {
        $payload = [
            'model' => $this->resolveModelId(),
            'response_format' => 'verbose_json',
            'temperature' => '0',
        ];

        $language = $this->normalizeLanguageCode($options['language_code'] ?? null);

        if ($language !== null) {
            $payload['language'] = $language;
        }

        return $payload;
    }

    private function resolveModelId(): string
    {
        $modelId = $this->modelId
            ?? app(AppSettingsService::class)->groqTranscriptionModel()
            ?? config('services.groq.transcription_model', self::MODEL_WHISPER_LARGE_V3);

        $allowedModels = config('services.groq.transcription_models', [
            self::MODEL_WHISPER_LARGE_V3,
            self::MODEL_WHISPER_LARGE_V3_TURBO,
        ]);

        if (! is_string($modelId) || ! in_array($modelId, $allowedModels, true)) {
            throw new GroqSpeechToTextException(ServiceUserMessage::unsupportedProviderModel('Groq'));
        }

        return $modelId;
    }

    private function normalizeLanguageCode(?string $languageCode): ?string
    {
        $languageCode = strtolower(trim((string) $languageCode));

        return match ($languageCode) {
            '', 'auto', 'multi', 'multilingual' => null,
            'fil', 'tgl', 'tagalog' => 'tl',
            default => $languageCode,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalizeTranscript(array $response): array
    {
        $words = is_array($response['words'] ?? null) ? $response['words'] : [];
        $segments = is_array($response['segments'] ?? null) ? $response['segments'] : [];
        $timestampRows = $words !== [] ? $words : $segments;
        $type = $words !== [] ? 'word' : 'segment';

        return [
            'text' => (string) ($response['text'] ?? ''),
            'timestamps' => array_values(array_map(
                fn (array $item): array => [
                    'text' => trim((string) ($item['word'] ?? $item['text'] ?? '')),
                    'start' => $item['start'] ?? null,
                    'end' => $item['end'] ?? null,
                    'type' => $type,
                    'speaker_id' => null,
                ],
                array_filter($timestampRows, 'is_array'),
            )),
        ];
    }

    /**
     * @param  array{path: string, name: string}  $file
     * @return array<string, mixed>
     */
    private function responseLogContext(Response $response, array $file): array
    {
        return [
            'status' => $response->status(),
            'request_id' => $response->header('x-request-id') ?? $response->json('x_groq.id'),
            'file_name' => $file['name'],
            'file_size_bytes' => is_file($file['path']) ? filesize($file['path']) : null,
            'response' => $response->json() ?? $response->body(),
        ];
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('Groq'),
            $status === 413 => 'Groq could not accept the audio because the file is too large.',
            $status === 429 => ServiceUserMessage::providerBusy('Groq'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('Groq'),
            in_array($status, [400, 422], true) => 'Groq could not accept the audio or language setting.',
            default => ServiceUserMessage::transcriptionFailed('Groq'),
        };
    }
}
