<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SplFileInfo;

class GoogleCloudSpeechToTextService
{
    public const MODEL_CHIRP_3 = 'chirp_3';

    public function __construct(private readonly string $credentials, private readonly string $modelId = self::MODEL_CHIRP_3, private readonly ?string $baseUrl = null, private readonly ?string $tokenUrl = null) {}

    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $credential = $this->credential();
        $file = $this->audioFile($audio);
        $contents = file_get_contents($file['path']);
        if ($contents === false) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $token = $this->accessToken($credential);
            $url = rtrim($this->baseUrl ?? (string) config('services.google_speech.base_url'), '/')
                .'/v2/projects/'.rawurlencode($credential['project_id']).'/locations/global/recognizers/_:recognize';
            $response = Http::withToken($token)->acceptJson()->asJson()->timeout((int) config('services.google_speech.timeout', 180))->post($url, [
                'config' => [
                    'autoDecodingConfig' => new \stdClass,
                    'model' => $this->modelId,
                    'languageCodes' => [$this->language($options['language_code'] ?? null)],
                    'features' => [
                        'enableWordTimeOffsets' => true,
                        'enableAutomaticPunctuation' => true,
                        'diarizationConfig' => new \stdClass,
                    ],
                ],
                'content' => base64_encode($contents),
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(ServiceUserMessage::cannotReachProvider('Google Cloud Speech'), 0, $exception);
        }

        $this->ensureSuccessful($response->status());
        $results = $response->json('results', []);
        $text = trim(collect($results)->map(fn ($result) => data_get($result, 'alternatives.0.transcript'))->filter()->implode(' '));
        $timestamps = [];
        foreach ($results as $result) {
            foreach (data_get($result, 'alternatives.0.words', []) as $word) {
                $timestamps[] = [
                    'text' => trim((string) ($word['word'] ?? '')),
                    'start' => $this->seconds($word['startOffset'] ?? null),
                    'end' => $this->seconds($word['endOffset'] ?? null),
                    'type' => 'word',
                    'speaker_id' => isset($word['speakerLabel']) ? 'speaker_'.$word['speakerLabel'] : null,
                ];
            }
        }
        if ($text === '') {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Google Cloud Speech'));
        }

        return ['text' => $text, 'timestamps' => $timestamps];
    }

    public function credential(): array
    {
        $credential = json_decode($this->credentials, true);
        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (! is_array($credential) || blank($credential[$key] ?? null)) {
                throw new RuntimeException('Google Cloud credentials must be a service-account JSON document.');
            }
        }
        if ($this->modelId !== self::MODEL_CHIRP_3) {
            throw new RuntimeException(ServiceUserMessage::unsupportedProviderModel('Google Cloud Speech'));
        }

        return $credential;
    }

    public function accessToken(?array $credential = null): string
    {
        $credential ??= $this->credential();
        $now = time();
        $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => $credential['client_email'], 'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => $this->tokenUrl ?? (string) config('services.google_speech.token_url'), 'iat' => $now, 'exp' => $now + 3600,
        ]);
        if (! openssl_sign($unsigned, $signature, $credential['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Google Cloud service-account private key is invalid.');
        }
        $assertion = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $response = Http::asForm()->timeout(15)->post($this->tokenUrl ?? (string) config('services.google_speech.token_url'), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
        ]);
        $this->ensureSuccessful($response->status());
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException(ServiceUserMessage::providerRejectedKey('Google Cloud Speech'));
        }

        return $token;
    }

    private function ensureSuccessful(int $status): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        throw new RuntimeException(match (true) {
            in_array($status, [400, 401, 403], true) => ServiceUserMessage::providerRejectedKey('Google Cloud Speech'),
            $status === 429 => ServiceUserMessage::providerBusy('Google Cloud Speech'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('Google Cloud Speech'),
            default => ServiceUserMessage::transcriptionFailed('Google Cloud Speech'),
        }, $status);
    }

    private function seconds(mixed $duration): ?float
    {
        return is_string($duration) && str_ends_with($duration, 's') ? (float) substr($duration, 0, -1) : null;
    }

    private function language(mixed $language): string
    {
        $language = strtolower(trim((string) $language));

        return match ($language) {
            'fil', 'tl', 'tgl', 'tagalog' => 'fil-PH', '', 'auto', 'multi', 'multilingual', 'en', 'eng' => 'en-US', default => $language
        };
    }

    private function audioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        $path = $audio instanceof UploadedFile || $audio instanceof SplFileInfo ? $audio->getRealPath() : $audio;
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        return ['path' => $path];
    }
}
