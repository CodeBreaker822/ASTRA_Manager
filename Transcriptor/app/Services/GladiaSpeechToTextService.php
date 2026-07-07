<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SplFileInfo;

class GladiaSpeechToTextService
{
    public const MODEL_SOLARIA = 'solaria';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId = self::MODEL_SOLARIA,
        private readonly ?string $baseUrl = null,
        private readonly ?int $pollIntervalMs = null,
        private readonly ?int $maxWaitSeconds = null,
    ) {}

    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->audioFile($audio);
        $contents = file_get_contents($file['path']);

        if ($contents === false) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $upload = $this->client()->attach('audio', $contents, $file['name'])->post($this->url('/upload'));
            $this->ensureSuccessful($upload->status(), 'Gladia');
            $audioUrl = $upload->json('audio_url');

            if (! is_string($audioUrl) || $audioUrl === '') {
                throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Gladia'));
            }

            $language = $this->language($options['language_code'] ?? null);
            $job = $this->client()->post($this->url('/pre-recorded'), [
                'audio_url' => $audioUrl,
                'diarization' => true,
                'language_config' => [
                    'languages' => $language ? [$language] : [],
                    'code_switching' => false,
                ],
            ]);
            $this->ensureSuccessful($job->status(), 'Gladia');
            $resultUrl = $job->json('result_url');

            if (! is_string($resultUrl) || $resultUrl === '') {
                throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Gladia'));
            }

            return $this->waitForResult($resultUrl);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(ServiceUserMessage::cannotReachProvider('Gladia'), 0, $exception);
        }
    }

    private function waitForResult(string $resultUrl): array
    {
        $deadline = microtime(true) + ($this->maxWaitSeconds ?? (int) config('services.gladia.max_wait_seconds', 300));

        do {
            $response = $this->client()->get($resultUrl);
            $this->ensureSuccessful($response->status(), 'Gladia');
            $status = strtolower((string) $response->json('status'));

            if ($status === 'done') {
                return $this->normalize($response->json() ?? []);
            }

            if ($status === 'error') {
                throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Gladia'));
            }

            usleep(($this->pollIntervalMs ?? (int) config('services.gladia.poll_interval_ms', 1000)) * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Gladia'));
    }

    private function normalize(array $payload): array
    {
        $utterances = data_get($payload, 'result.transcription.utterances', []);
        $fullTranscript = data_get($payload, 'result.transcription.full_transcript');
        $timestamps = [];

        foreach (is_array($utterances) ? $utterances : [] as $utterance) {
            foreach (is_array($utterance['words'] ?? null) ? $utterance['words'] : [] as $word) {
                $timestamps[] = [
                    'text' => trim((string) ($word['word'] ?? $word['text'] ?? '')),
                    'start' => $word['start'] ?? null,
                    'end' => $word['end'] ?? null,
                    'type' => 'word',
                    'speaker_id' => isset($utterance['speaker']) ? 'speaker_'.$utterance['speaker'] : null,
                ];
            }
        }

        $text = is_string($fullTranscript) ? trim($fullTranscript) : trim(collect($utterances)->pluck('text')->implode(' '));

        if ($text === '') {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Gladia'));
        }

        return ['text' => $text, 'timestamps' => $timestamps];
    }

    private function client()
    {
        if (trim($this->apiKey) === '' || $this->modelId !== self::MODEL_SOLARIA) {
            throw new RuntimeException(ServiceUserMessage::missingApiKey('Gladia'));
        }

        return Http::withHeaders(['x-gladia-key' => trim($this->apiKey)])
            ->acceptJson()->timeout((int) config('services.gladia.timeout', 120));
    }

    private function ensureSuccessful(int $status, string $provider): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new RuntimeException(match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey($provider),
            $status === 429 => ServiceUserMessage::providerBusy($provider),
            $status >= 500 => ServiceUserMessage::providerUnavailable($provider),
            default => ServiceUserMessage::transcriptionFailed($provider),
        }, $status);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl ?? (string) config('services.gladia.base_url'), '/').$path;
    }

    private function language(mixed $language): ?string
    {
        $language = strtolower(trim((string) $language));

        return match ($language) {
            '', 'auto', 'multi', 'multilingual' => null,
            'fil', 'tgl', 'tagalog' => 'tl',
            default => $language,
        };
    }

    private function audioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        $path = $audio instanceof UploadedFile || $audio instanceof SplFileInfo ? $audio->getRealPath() : $audio;

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        return ['path' => $path, 'name' => $audio instanceof UploadedFile ? ($audio->getClientOriginalName() ?: $audio->getFilename()) : basename($path)];
    }
}
