<?php

namespace App\Services;

use App\Exceptions\ElevenLabsSpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class ElevenLabsSpeechToTextService
{
    public const MODEL_SCRIBE_V2 = 'scribe_v2';

    public const MODEL_SCRIBE_V1 = 'scribe_v1';

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
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->client()
                ->attach('file', $stream, $file['name'])
                ->post($this->getEndpoint(), $this->payload($options));
        } catch (ConnectionException $exception) {
            throw new ElevenLabsSpeechToTextException(
                ServiceUserMessage::cannotReachProvider('ElevenLabs'),
                0,
                $exception,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->failed()) {
            Log::error('ElevenLabs transcription request failed.', $this->responseLogContext($response, $file));

            throw new ElevenLabsSpeechToTextException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status()
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
                throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
            ];
        }

        if (! is_file($audio)) {
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
        ];
    }

    private function client(): PendingRequest
    {
        $apiKey = $this->apiKey
            ?? app(AppSettingsService::class)->elevenLabsApiKey()
            ?? config('services.elevenlabs.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::missingApiKey('ElevenLabs'));
        }

        return Http::withHeaders([
            'xi-api-key' => trim($apiKey),
        ])->timeout($this->timeout ?? (int) config('services.elevenlabs.timeout', 120));
    }

    private function getEndpoint(): string
    {
        return $this->endpoint ?? (string) config('services.elevenlabs.speech_to_text_url');
    }

    private function payload(array $options): array
    {
        $payload = [
            'model_id' => $this->resolveModelId($options['model_id'] ?? null),
            'file_format' => 'other',
        ];

        $languageCode = $this->normalizeLanguageCode($options['language_code'] ?? null);

        if ($languageCode !== null) {
            $payload['language_code'] = $languageCode;
        }

        if (array_key_exists('diarize', $options)) {
            $payload['diarize'] = $this->booleanFormValue($options['diarize']);
        }

        if (array_key_exists('tag_audio_events', $options)) {
            $payload['tag_audio_events'] = $this->booleanFormValue($options['tag_audio_events']);
        }

        if (isset($options['timestamps_granularity'])) {
            $payload['timestamps_granularity'] = (string) $options['timestamps_granularity'];
        }

        return $payload;
    }

    private function normalizeLanguageCode(?string $languageCode): ?string
    {
        $languageCode = strtolower(trim((string) $languageCode));

        return match ($languageCode) {
            '', 'auto', 'multi', 'multilingual' => null,
            default => $languageCode,
        };
    }

    private function booleanFormValue(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    private function resolveModelId(?string $modelId): string
    {
        $modelId = $modelId
            ?? $this->modelId
            ?? app(AppSettingsService::class)->elevenLabsModel()
            ?? config('services.elevenlabs.speech_to_text_model', self::MODEL_SCRIBE_V2);

        $allowedModels = config('services.elevenlabs.speech_to_text_models', [
            self::MODEL_SCRIBE_V2,
        ]);

        if (! is_string($modelId) || ! in_array($modelId, $allowedModels, true)) {
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::unsupportedProviderModel('ElevenLabs'));
        }

        return $modelId;
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('ElevenLabs'),
            $status === 429 => ServiceUserMessage::providerBusy('ElevenLabs'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('ElevenLabs'),
            in_array($status, [400, 422], true) => 'ElevenLabs could not accept the audio or language setting.',
            default => ServiceUserMessage::transcriptionFailed('ElevenLabs'),
        };
    }

    /**
     * @param  array{path: string, name: string}  $file
     * @return array<string, mixed>
     */
    private function responseLogContext(Response $response, array $file): array
    {
        return [
            'status' => $response->status(),
            'file_name' => $file['name'],
            'file_size_bytes' => is_file($file['path']) ? filesize($file['path']) : null,
            'response' => $response->json() ?? $response->body(),
        ];
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalizeTranscript(array $response): array
    {
        $words = is_array($response['words'] ?? null) ? $response['words'] : [];

        return [
            'text' => (string) ($response['text'] ?? ''),
            'timestamps' => array_values(array_map(
                fn (array $word): array => [
                    'text' => (string) ($word['text'] ?? ''),
                    'start' => $word['start'] ?? null,
                    'end' => $word['end'] ?? null,
                    'type' => $word['type'] ?? null,
                    'speaker_id' => $word['speaker_id'] ?? null,
                ],
                array_filter($words, 'is_array'),
            )),
        ];
    }
}
