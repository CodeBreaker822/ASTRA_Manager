<?php

namespace App\Services;

use App\Exceptions\SpeechmaticsSpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class SpeechmaticsSpeechToTextService
{
    public const MODEL_ENHANCED = 'enhanced';

    public const MODEL_MELIA_1 = 'melia-1';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $modelId = null,
        private readonly ?int $timeout = null,
        private readonly ?int $pollIntervalMs = null,
        private readonly ?int $maxWaitSeconds = null,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->resolveAudioFile($audio);
        $jobId = $this->createJob($file, $options);

        $this->waitForJob($jobId, $file);

        return $this->retrieveTranscript($jobId, $file);
    }

    /**
     * @param  array{path: string, name: string}  $file
     */
    private function createJob(array $file, array $options): string
    {
        $contents = file_get_contents($file['path']);

        if ($contents === false) {
            throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->client()
                ->attach('data_file', $contents, $file['name'])
                ->post($this->jobsUrl(), [
                    'config' => json_encode($this->jobConfig($options), JSON_THROW_ON_ERROR),
                ]);
        } catch (ConnectionException $exception) {
            throw new SpeechmaticsSpeechToTextException(
                ServiceUserMessage::cannotReachProvider('Speechmatics'),
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            Log::error('Speechmatics transcription job creation failed.', $this->responseLogContext($response, $file));

            throw new SpeechmaticsSpeechToTextException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status(),
            );
        }

        $jobId = $response->json('id');

        if (! is_string($jobId) || trim($jobId) === '') {
            throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::transcriptionFailed('Speechmatics'));
        }

        return $jobId;
    }

    /**
     * @param  array{path: string, name: string}  $file
     */
    private function waitForJob(string $jobId, array $file): void
    {
        $deadline = time() + $this->maxWaitSeconds();

        do {
            try {
                $response = $this->client()->get($this->jobUrl($jobId));
            } catch (ConnectionException $exception) {
                throw new SpeechmaticsSpeechToTextException(
                    ServiceUserMessage::cannotReachProvider('Speechmatics'),
                    0,
                    $exception,
                );
            }

            if ($response->failed()) {
                Log::error('Speechmatics transcription job polling failed.', $this->responseLogContext($response, $file, $jobId));

                throw new SpeechmaticsSpeechToTextException(
                    $this->userMessageForFailedResponse($response->status()),
                    $response->status(),
                );
            }

            $status = $this->jobStatus($response->json() ?? []);

            if ($status === 'done') {
                return;
            }

            if (in_array($status, ['rejected', 'deleted', 'expired'], true)) {
                $responsePayload = $response->json() ?? [];

                Log::warning('Speechmatics transcription job ended without transcript.', [
                    'job_id' => $jobId,
                    'status' => $status,
                    'file_name' => $file['name'],
                    'response' => $responsePayload ?: $response->body(),
                ]);

                throw new SpeechmaticsSpeechToTextException($this->userMessageForRejectedJob($responsePayload));
            }

            usleep($this->pollIntervalMs() * 1000);
        } while (time() < $deadline);

        throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::transcriptionFailed('Speechmatics'));
    }

    /**
     * @param  array{path: string, name: string}  $file
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function retrieveTranscript(string $jobId, array $file): array
    {
        try {
            $response = $this->client()->get($this->jobTranscriptUrl($jobId), [
                'format' => 'json-v2',
            ]);
        } catch (ConnectionException $exception) {
            throw new SpeechmaticsSpeechToTextException(
                ServiceUserMessage::cannotReachProvider('Speechmatics'),
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            Log::error('Speechmatics transcript retrieval failed.', $this->responseLogContext($response, $file, $jobId));

            throw new SpeechmaticsSpeechToTextException(
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
                throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
            ];
        }

        if (! is_file($audio)) {
            throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
        ];
    }

    private function client(): PendingRequest
    {
        $apiKey = $this->apiKey
            ?? app(AppSettingsService::class)->speechmaticsApiKey()
            ?? config('services.speechmatics.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::missingApiKey('Speechmatics'));
        }

        return Http::withHeaders([
            'Authorization' => 'Bearer '.trim($apiKey),
        ])->timeout($this->timeout ?? (int) config('services.speechmatics.timeout', 120));
    }

    private function jobConfig(array $options): array
    {
        return [
            'type' => 'transcription',
            'transcription_config' => [
                'language' => $this->resolveLanguageCode($options),
                'model' => $this->resolveModelId(),
                'diarization' => 'speaker',
            ],
        ];
    }

    private function resolveModelId(): string
    {
        $modelId = $this->modelId
            ?? app(AppSettingsService::class)->speechmaticsModel()
            ?? config('services.speechmatics.model', self::MODEL_MELIA_1);

        $allowedModels = config('services.speechmatics.speech_to_text_models', [
            'enhanced',
            'melia-1',
        ]);

        if (! is_string($modelId) || ! in_array($modelId, $allowedModels, true)) {
            throw new SpeechmaticsSpeechToTextException(ServiceUserMessage::unsupportedProviderModel('Speechmatics'));
        }

        return $modelId;
    }

    private function resolveLanguageCode(array $options): string
    {
        if ($this->resolveModelId() === self::MODEL_MELIA_1) {
            return 'multi';
        }

        return $this->normalizeLanguageCode(
            $options['language_code'] ?? config('services.speechmatics.language', 'auto')
        );
    }

    private function normalizeLanguageCode(?string $languageCode): string
    {
        $languageCode = strtolower(trim((string) $languageCode));

        return match ($languageCode) {
            '', 'auto', 'multi', 'multilingual' => 'auto',
            'eng' => 'en',
            'fil', 'tgl', 'tagalog' => 'tl',
            default => $languageCode,
        };
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('Speechmatics'),
            $status === 429 => ServiceUserMessage::providerBusy('Speechmatics'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('Speechmatics'),
            default => ServiceUserMessage::transcriptionFailed('Speechmatics'),
        };
    }

    private function userMessageForRejectedJob(array $response): string
    {
        $errors = $response['job']['errors'] ?? [];

        if (is_array($errors)) {
            foreach ($errors as $error) {
                $message = is_array($error) ? (string) ($error['message'] ?? '') : '';
                $normalized = strtolower($message);

                if (str_contains($normalized, 'language') && str_contains($normalized, 'not supported')) {
                    return 'Speechmatics accepted the audio, but does not support the detected language. Choose English for Speechmatics, or use Deepgram or ElevenLabs for Filipino or mixed-language speech.';
                }
            }
        }

        return ServiceUserMessage::transcriptionFailed('Speechmatics');
    }

    private function jobStatus(array $response): string
    {
        $status = $response['job']['status'] ?? $response['status'] ?? '';

        return strtolower((string) $status);
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalizeTranscript(array $response): array
    {
        $results = is_array($response['results'] ?? null) ? $response['results'] : [];
        $text = '';
        $timestamps = [];

        foreach ($results as $item) {
            if (! is_array($item)) {
                continue;
            }

            $alternative = $item['alternatives'][0] ?? null;

            if (! is_array($alternative)) {
                continue;
            }

            $content = trim((string) ($alternative['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $type = (string) ($item['type'] ?? 'word');

            if ($type === 'punctuation') {
                $text .= $content;
                continue;
            }

            $text .= ($text === '' ? '' : ' ').$content;
            $timestamps[] = [
                'text' => $content,
                'start' => $item['start_time'] ?? null,
                'end' => $item['end_time'] ?? null,
                'type' => 'word',
                'speaker_id' => isset($alternative['speaker']) ? 'speaker_'.$alternative['speaker'] : null,
            ];
        }

        return [
            'text' => trim($text),
            'timestamps' => $timestamps,
        ];
    }

    /**
     * @param  array{path: string, name: string}  $file
     * @return array<string, mixed>
     */
    private function responseLogContext(Response $response, array $file, ?string $jobId = null): array
    {
        return [
            'status' => $response->status(),
            'job_id' => $jobId,
            'file_name' => $file['name'],
            'file_size_bytes' => is_file($file['path']) ? filesize($file['path']) : null,
            'response' => $response->json() ?? $response->body(),
        ];
    }

    private function jobsUrl(): string
    {
        return $this->apiBaseUrl().'/jobs';
    }

    private function jobUrl(string $jobId): string
    {
        return $this->jobsUrl().'/'.rawurlencode($jobId);
    }

    private function jobTranscriptUrl(string $jobId): string
    {
        return $this->jobUrl($jobId).'/transcript';
    }

    private function apiBaseUrl(): string
    {
        return rtrim($this->baseUrl ?? (string) config('services.speechmatics.base_url'), '/');
    }

    private function pollIntervalMs(): int
    {
        return max(250, $this->pollIntervalMs ?? (int) config('services.speechmatics.poll_interval_ms', 1000));
    }

    private function maxWaitSeconds(): int
    {
        return max(10, $this->maxWaitSeconds ?? (int) config('services.speechmatics.max_wait_seconds', 300));
    }
}
