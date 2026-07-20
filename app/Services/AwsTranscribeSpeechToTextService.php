<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

class AwsTranscribeSpeechToTextService
{
    public const MODEL_STANDARD = 'standard';

    public function __construct(
        private readonly string $credentials,
        private readonly string $modelId = self::MODEL_STANDARD,
        private ?S3Client $s3 = null,
        private ?TranscribeServiceClient $transcribeClient = null,
        private readonly ?int $pollIntervalMs = null,
        private readonly ?int $maxWaitSeconds = null,
    ) {}

    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $credential = $this->credential();
        $file = $this->audioFile($audio);
        $objectKey = 'jerva-transcriber/'.Str::uuid().'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
        $jobName = 'jerva-'.str_replace('-', '', (string) Str::uuid());

        try {
            $this->s3($credential)->putObject([
                'Bucket' => $credential['bucket'], 'Key' => $objectKey,
                'SourceFile' => $file['path'], 'ContentType' => $file['mime_type'],
            ]);

            $payload = [
                'TranscriptionJobName' => $jobName,
                'Media' => ['MediaFileUri' => 's3://'.$credential['bucket'].'/'.$objectKey],
                'Settings' => ['ShowSpeakerLabels' => true, 'MaxSpeakerLabels' => 10],
            ];
            $language = $this->language($options['language_code'] ?? null);
            $payload[$language ? 'LanguageCode' : 'IdentifyLanguage'] = $language ?: true;
            $this->transcribeClient($credential)->startTranscriptionJob($payload);

            return $this->waitForResult($credential, $jobName);
        } catch (AwsException $exception) {
            $status = $exception->getStatusCode();
            throw new RuntimeException(match (true) {
                in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('Amazon Transcribe'),
                $status === 429 => ServiceUserMessage::providerBusy('Amazon Transcribe'),
                $status !== null && $status >= 500 => ServiceUserMessage::providerUnavailable('Amazon Transcribe'),
                default => ServiceUserMessage::transcriptionFailed('Amazon Transcribe'),
            }, $status ?? 0, $exception);
        } finally {
            try {
                $this->s3($credential)->deleteObject(['Bucket' => $credential['bucket'], 'Key' => $objectKey]);
                $this->transcribeClient($credential)->deleteTranscriptionJob(['TranscriptionJobName' => $jobName]);
            } catch (\Throwable) {
                // Cleanup must never prevent the provider fallback chain.
            }
        }
    }

    public function credential(): array
    {
        $credential = json_decode($this->credentials, true);
        foreach (['access_key_id', 'secret_access_key', 'region', 'bucket'] as $key) {
            if (! is_array($credential) || blank($credential[$key] ?? null)) {
                throw new RuntimeException('Amazon Transcribe credentials must contain access_key_id, secret_access_key, region, and bucket.');
            }
        }
        if ($this->modelId !== self::MODEL_STANDARD) {
            throw new RuntimeException(ServiceUserMessage::unsupportedProviderModel('Amazon Transcribe'));
        }

        return $credential;
    }

    public function checkConnection(): void
    {
        $credential = $this->credential();
        $this->s3($credential)->headBucket(['Bucket' => $credential['bucket']]);
    }

    private function waitForResult(array $credential, string $jobName): array
    {
        $deadline = microtime(true) + ($this->maxWaitSeconds ?? (int) config('services.aws_transcribe.max_wait_seconds', 300));
        do {
            $result = $this->transcribeClient($credential)->getTranscriptionJob(['TranscriptionJobName' => $jobName]);
            $job = $result->get('TranscriptionJob') ?? [];
            $status = strtoupper((string) ($job['TranscriptionJobStatus'] ?? ''));
            if ($status === 'COMPLETED') {
                $uri = $job['Transcript']['TranscriptFileUri'] ?? null;
                if (! is_string($uri) || $uri === '') {
                    throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Amazon Transcribe'));
                }
                $response = Http::timeout(30)->get($uri);
                if ($response->failed()) {
                    throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Amazon Transcribe'), $response->status());
                }

                return $this->normalize($response->json() ?? []);
            }
            if ($status === 'FAILED') {
                throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Amazon Transcribe'));
            }
            usleep(($this->pollIntervalMs ?? (int) config('services.aws_transcribe.poll_interval_ms', 1000)) * 1000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Amazon Transcribe'));
    }

    private function normalize(array $payload): array
    {
        $text = trim((string) data_get($payload, 'results.transcripts.0.transcript', ''));
        if ($text === '') {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('Amazon Transcribe'));
        }
        $timestamps = [];
        foreach (data_get($payload, 'results.items', []) as $item) {
            if (($item['type'] ?? null) !== 'pronunciation') {
                continue;
            }
            $timestamps[] = [
                'text' => trim((string) data_get($item, 'alternatives.0.content', '')),
                'start' => isset($item['start_time']) ? (float) $item['start_time'] : null,
                'end' => isset($item['end_time']) ? (float) $item['end_time'] : null,
                'type' => 'word',
                'speaker_id' => isset($item['speaker_label']) ? 'speaker_'.$item['speaker_label'] : null,
            ];
        }

        return ['text' => $text, 'timestamps' => $timestamps];
    }

    private function s3(array $credential): S3Client
    {
        return $this->s3 ??= new S3Client($this->clientConfig($credential));
    }

    private function transcribeClient(array $credential): TranscribeServiceClient
    {
        return $this->transcribeClient ??= new TranscribeServiceClient($this->clientConfig($credential));
    }

    private function clientConfig(array $credential): array
    {
        return ['version' => 'latest', 'region' => $credential['region'], 'credentials' => ['key' => $credential['access_key_id'], 'secret' => $credential['secret_access_key']]];
    }

    private function language(mixed $language): ?string
    {
        $language = strtolower(trim((string) $language));

        return match ($language) {
            '', 'auto', 'multi', 'multilingual' => null, 'fil', 'tl', 'tgl', 'tagalog' => 'tl-PH', 'en', 'eng' => 'en-US', default => $language
        };
    }

    private function audioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        $path = $audio instanceof UploadedFile || $audio instanceof SplFileInfo ? $audio->getRealPath() : $audio;
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $path,
            'name' => $audio instanceof UploadedFile ? ($audio->getClientOriginalName() ?: $audio->getFilename()) : basename($path),
            'mime_type' => $audio instanceof UploadedFile ? ($audio->getMimeType() ?: 'application/octet-stream') : (mime_content_type($path) ?: 'application/octet-stream'),
        ];
    }
}
