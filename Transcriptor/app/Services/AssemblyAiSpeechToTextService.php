<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SplFileInfo;

class AssemblyAiSpeechToTextService
{
    public const MODEL_UNIVERSAL_2 = 'universal-2';

    public const MODEL_UNIVERSAL_3_PRO = 'universal-3-pro';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId = self::MODEL_UNIVERSAL_2,
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
            $upload = $this->client()->withBody($contents, 'application/octet-stream')->post($this->url('/upload'));
            $this->ensureSuccessful($upload->status());
            $audioUrl = $upload->json('upload_url');

            if (! is_string($audioUrl) || $audioUrl === '') {
                throw new RuntimeException(ServiceUserMessage::transcriptionFailed('AssemblyAI'));
            }

            $payload = [
                'audio_url' => $audioUrl,
                'speech_models' => [$this->modelId],
                'speaker_labels' => true,
                'format_text' => true,
                'punctuate' => true,
            ];
            $language = $this->language($options['language_code'] ?? null);
            $payload[$language ? 'language_code' : 'language_detection'] = $language ?: true;

            $job = $this->client()->post($this->url('/transcript'), $payload);
            $this->ensureSuccessful($job->status());
            $id = $job->json('id');

            if (! is_string($id) || $id === '') {
                throw new RuntimeException(ServiceUserMessage::transcriptionFailed('AssemblyAI'));
            }

            return $this->waitForResult($id);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(ServiceUserMessage::cannotReachProvider('AssemblyAI'), 0, $exception);
        }
    }

    private function waitForResult(string $id): array
    {
        $deadline = microtime(true) + ($this->maxWaitSeconds ?? (int) config('services.assemblyai.max_wait_seconds', 300));

        do {
            $response = $this->client()->get($this->url('/transcript/'.rawurlencode($id)));
            $this->ensureSuccessful($response->status());
            $status = strtolower((string) $response->json('status'));

            if ($status === 'completed') {
                return $this->normalize($response->json() ?? []);
            }

            if ($status === 'error') {
                throw new RuntimeException(ServiceUserMessage::transcriptionFailed('AssemblyAI'));
            }

            usleep(($this->pollIntervalMs ?? (int) config('services.assemblyai.poll_interval_ms', 1000)) * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(ServiceUserMessage::transcriptionFailed('AssemblyAI'));
    }

    private function normalize(array $payload): array
    {
        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('AssemblyAI'));
        }

        return ['text' => $text, 'timestamps' => array_values(array_map(fn (array $word): array => [
            'text' => trim((string) ($word['text'] ?? '')),
            'start' => isset($word['start']) ? $word['start'] / 1000 : null,
            'end' => isset($word['end']) ? $word['end'] / 1000 : null,
            'type' => 'word',
            'speaker_id' => isset($word['speaker']) ? 'speaker_'.$word['speaker'] : null,
        ], array_filter($payload['words'] ?? [], 'is_array')))];
    }

    private function client()
    {
        if (trim($this->apiKey) === '' || ! in_array($this->modelId, [self::MODEL_UNIVERSAL_2, self::MODEL_UNIVERSAL_3_PRO], true)) {
            throw new RuntimeException(ServiceUserMessage::missingApiKey('AssemblyAI'));
        }

        return Http::withHeaders(['Authorization' => trim($this->apiKey)])->acceptJson()->timeout((int) config('services.assemblyai.timeout', 120));
    }

    private function ensureSuccessful(int $status): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        throw new RuntimeException(match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('AssemblyAI'),
            $status === 429 => ServiceUserMessage::providerBusy('AssemblyAI'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('AssemblyAI'),
            default => ServiceUserMessage::transcriptionFailed('AssemblyAI'),
        }, $status);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl ?? (string) config('services.assemblyai.base_url'), '/').$path;
    }

    private function language(mixed $language): ?string
    {
        $language = strtolower(trim((string) $language));

        return match ($language) {
            '', 'auto', 'multi', 'multilingual' => null, 'fil', 'tgl', 'tagalog' => 'tl', default => str_replace('-', '_', $language)
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
