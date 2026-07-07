<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SplFileInfo;

class AzureSpeechToTextService
{
    public const MODEL_FAST_TRANSCRIPTION = 'fast-transcription';

    public function __construct(private readonly string $credentials, private readonly string $modelId = self::MODEL_FAST_TRANSCRIPTION, private readonly ?string $endpoint = null) {}

    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $credential = $this->credential();
        $file = $this->audioFile($audio);
        $contents = file_get_contents($file['path']);
        if ($contents === false) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $locale = $this->locale($options['language_code'] ?? null);
        $definition = [
            'locales' => [$locale],
            'profanityFilterMode' => 'None',
            'channels' => [0, 1],
            'diarization' => ['maxSpeakers' => 10],
        ];

        try {
            $response = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $credential['key']])
                ->acceptJson()->timeout((int) config('services.azure_speech.timeout', 180))
                ->attach('audio', $contents, $file['name'])
                ->attach('definition', json_encode($definition, JSON_THROW_ON_ERROR))
                ->post($this->endpoint ?? sprintf((string) config('services.azure_speech.fast_transcription_url'), $credential['region']));
        } catch (ConnectionException $exception) {
            throw new RuntimeException(ServiceUserMessage::cannotReachProvider('Azure Speech'), 0, $exception);
        }

        $this->ensureSuccessful($response->status());
        $payload = $response->json() ?? [];
        $text = trim(collect($payload['combinedPhrases'] ?? [])->pluck('text')->implode(' '));
        $timestamps = [];
        foreach ($payload['phrases'] ?? [] as $phrase) {
            foreach (is_array($phrase['words'] ?? null) ? $phrase['words'] : [] as $word) {
                $start = isset($word['offsetMilliseconds']) ? $word['offsetMilliseconds'] / 1000 : null;
                $timestamps[] = [
                    'text' => trim((string) ($word['text'] ?? '')),
                    'start' => $start,
                    'end' => $start !== null && isset($word['durationMilliseconds']) ? $start + ($word['durationMilliseconds'] / 1000) : null,
                    'type' => 'word',
                    'speaker_id' => isset($phrase['speaker']) ? 'speaker_'.$phrase['speaker'] : null,
                ];
            }
        }
        if ($text === '') {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Azure Speech'));
        }

        return ['text' => $text, 'timestamps' => $timestamps];
    }

    public function credential(): array
    {
        $credential = json_decode($this->credentials, true);
        if ($this->modelId !== self::MODEL_FAST_TRANSCRIPTION || ! is_array($credential) || blank($credential['key'] ?? null) || blank($credential['region'] ?? null)) {
            throw new RuntimeException('Azure Speech credentials must contain key and region.');
        }

        return ['key' => trim((string) $credential['key']), 'region' => strtolower(trim((string) $credential['region']))];
    }

    private function ensureSuccessful(int $status): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        throw new RuntimeException(match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('Azure Speech'),
            $status === 429 => ServiceUserMessage::providerBusy('Azure Speech'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('Azure Speech'),
            default => ServiceUserMessage::transcriptionFailed('Azure Speech'),
        }, $status);
    }

    private function locale(mixed $language): string
    {
        $language = strtolower(trim((string) $language));

        return match ($language) {
            'fil', 'tl', 'tgl', 'tagalog' => 'fil-PH', 'en', 'eng', '', 'auto', 'multi', 'multilingual' => 'en-US', default => str_contains($language, '-') ? $language : $language.'-'.$language
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
