<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

class RunPodSpeechToTextService
{
    public const MODEL_CEBUANO_BISAYA_EPOCH1_CT2 = 'cebuano-bisaya-epoch1-ct2';

    public const MODEL_SERVERLESS_TRANSCRIPTOR = self::MODEL_CEBUANO_BISAYA_EPOCH1_CT2;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId = self::MODEL_SERVERLESS_TRANSCRIPTOR,
        private readonly ?string $runsyncUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        return $this->transcribeBatch([
            [
                'audio' => $audio,
                'clip_index' => $options['clip_index'] ?? null,
                'clip_start_ms' => $options['clip_start_ms'] ?? null,
                'clip_end_ms' => $options['clip_end_ms'] ?? null,
                'language_code' => $options['language_code'] ?? null,
            ],
        ], $options)[0];
    }

    /**
     * @param  array<int, array{audio: UploadedFile|string|SplFileInfo, clip_index?: mixed, clip_start_ms?: mixed, clip_end_ms?: mixed, language_code?: mixed}>  $clips
     * @return array{job_id: string, status: string|null, temporary_paths: array<int, string>, response: array<string, mixed>}
     */
    public function submitBatchAsync(array $clips, array $options = []): array
    {
        $clips = array_values($clips);

        if ($clips === []) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        if (count($clips) > 20 || $this->batchDurationTooLarge($clips)) {
            throw new RuntimeException('Audio is too big.', 422);
        }

        try {
            [$payloadClips, $temporaryPaths] = $this->payloadClips($clips, $options);
            $response = $this->client()->post($this->runUrl(), [
                'input' => [
                    'action' => 'transcribe',
                    'clips' => $payloadClips,
                    'beam_size' => (int) config('services.runpod.beam_size', 5),
                    'vad_filter' => filter_var(config('services.runpod.vad_filter', false), FILTER_VALIDATE_BOOLEAN),
                ],
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(ServiceUserMessage::cannotReachProvider('RunPod'), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->messageForStatus($response->status()), $response->status());
        }

        $payload = $response->json() ?? [];
        $jobId = trim((string) data_get($payload, 'id', ''));

        if ($jobId === '') {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('RunPod'));
        }

        return [
            'job_id' => $jobId,
            'status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
            'temporary_paths' => $temporaryPaths,
            'response' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $jobId): array
    {
        try {
            $response = $this->client()->get($this->statusUrl($jobId));
        } catch (ConnectionException $exception) {
            throw new RuntimeException(ServiceUserMessage::cannotReachProvider('RunPod'), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->messageForStatus($response->status()), $response->status());
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceClips
     * @return array<int, array{text: string, timestamps: array<int, array<string, mixed>>, clip_index?: int|null, clip_start_ms?: int|null, clip_end_ms?: int|null, queue_index?: int}>
     */
    public function normalizeSubmittedBatch(array $payload, array $sourceClips): array
    {
        return $this->normalizeBatch($payload, $sourceClips);
    }

    /**
     * @param  array<int, array{audio: UploadedFile|string|SplFileInfo, clip_index?: mixed, clip_start_ms?: mixed, clip_end_ms?: mixed, language_code?: mixed}>  $clips
     * @return array<int, array{text: string, timestamps: array<int, array<string, mixed>>, clip_index?: int|null, clip_start_ms?: int|null, clip_end_ms?: int|null, queue_index?: int}>
     */
    public function transcribeBatch(array $clips, array $options = []): array
    {
        $clips = array_values($clips);

        if ($clips === []) {
            return [];
        }

        if (count($clips) > 20 || $this->batchDurationTooLarge($clips)) {
            throw new RuntimeException('Audio is too big.', 422);
        }

        try {
            [$payloadClips] = $this->payloadClips($clips, $options);
            $response = $this->submitAndWait([
                'input' => [
                    'action' => 'transcribe',
                    'clips' => $payloadClips,
                    'beam_size' => (int) config('services.runpod.beam_size', 5),
                    'vad_filter' => filter_var(config('services.runpod.vad_filter', false), FILTER_VALIDATE_BOOLEAN),
                ],
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(ServiceUserMessage::cannotReachProvider('RunPod'), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->messageForStatus($response->status()), $response->status());
        }

        return $this->normalizeBatch($response->json() ?? [], $clips);
    }

    private function client()
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException(ServiceUserMessage::missingApiKey('RunPod'));
        }

        return Http::withToken(trim($this->apiKey))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout ?? (int) config('services.runpod.timeout', 300));
    }

    private function endpoint(): string
    {
        $endpoint = trim((string) $this->runsyncUrl);

        if ($endpoint === '') {
            throw new RuntimeException('RunPod endpoint is not configured.');
        }

        return $endpoint;
    }

    private function runUrl(): string
    {
        return preg_replace('#/runsync(?:\?.*)?$#', '/run', $this->endpoint()) ?: $this->endpoint();
    }

    private function statusUrl(string $jobId): string
    {
        return preg_replace('#/run$#', '/status/'.rawurlencode($jobId), $this->runUrl()) ?: $this->runUrl();
    }

    private function submitAndWait(array $payload)
    {
        $client = $this->client();
        $runUrl = $this->runUrl();
        $response = $client->post($runUrl, $payload);

        if ($response->failed()) {
            return $response;
        }

        $jobId = trim((string) $response->json('id'));

        if ($jobId === '') {
            return $response;
        }

        $statusUrl = $this->statusUrl($jobId);
        $deadline = microtime(true) + ($this->timeout ?? (int) config('services.runpod.timeout', 1500));

        do {
            $status = $client->get($statusUrl);

            if ($status->failed()) {
                return $status;
            }

            $state = strtoupper((string) $status->json('status'));

            if (in_array($state, ['COMPLETED', 'FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
                return $status;
            }

            usleep(2_000_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('RunPod transcription timed out while waiting for the submitted job.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function payloadClips(array $clips, array $options): array
    {
        $payloadClips = [];
        $temporaryPaths = [];
        $encodedBytes = 0;

        foreach ($clips as $index => $clip) {
            $file = $this->audioFile($clip['audio']);
            $contents = file_get_contents($file['path']);
            if ($contents === false) {
                throw new RuntimeException(ServiceUserMessage::audioReadFailed());
            }
            $encoded = base64_encode($contents);
            $encodedBytes += strlen($encoded);
            $payloadClips[] = $this->directClipInputPayload($file, $encoded, [
                ...$options,
                ...$clip,
                'queue_index' => $index,
            ]);
        }

        if ($encodedBytes <= 9_000_000) {
            return [$payloadClips, $temporaryPaths];
        }

        $payloadClips = [];
        foreach ($clips as $index => $clip) {
            $file = $this->audioFile($clip['audio']);
            $temporaryPath = $this->storeTemporaryAudio($file);
            $temporaryPaths[] = $temporaryPath;
            $payloadClips[] = $this->clipInputPayload($temporaryPath, [...$options, ...$clip, 'queue_index' => $index]);
        }

        return [$payloadClips, $temporaryPaths];
    }

    /**
     * @return array<string, mixed>
     */
    private function clipInputPayload(string $temporaryPath, array $options): array
    {
        $language = $this->language($options['language_code'] ?? null);

        return array_filter([
            'queue_index' => $options['queue_index'] ?? null,
            'audio_url' => $this->temporaryAudioUrl($temporaryPath),
            'clip_index' => $options['clip_index'] ?? null,
            'clip_start_ms' => $options['clip_start_ms'] ?? null,
            'clip_end_ms' => $options['clip_end_ms'] ?? null,
            'language' => $language,
            'beam_size' => (int) config('services.runpod.beam_size', 5),
            'vad_filter' => filter_var(config('services.runpod.vad_filter', false), FILTER_VALIDATE_BOOLEAN),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function directClipInputPayload(array $file, string $encoded, array $options): array
    {
        return array_filter([
            'queue_index' => $options['queue_index'] ?? null,
            'audio_base64' => $encoded,
            'audio_name' => $file['name'],
            'audio_mime_type' => $file['mime_type'],
            'clip_index' => $options['clip_index'] ?? null,
            'clip_start_ms' => $options['clip_start_ms'] ?? null,
            'clip_end_ms' => $options['clip_end_ms'] ?? null,
            'language' => $this->language($options['language_code'] ?? null),
            'beam_size' => (int) config('services.runpod.beam_size', 5),
            'vad_filter' => false,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalize(array $payload): array
    {
        $status = strtoupper((string) data_get($payload, 'status', ''));

        if (in_array($status, ['FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('RunPod'));
        }

        $output = data_get($payload, 'output', $payload);
        $output = is_array($output) ? $output : ['text' => $output];

        if (is_string($output['error'] ?? null) && trim($output['error']) !== '') {
            throw new RuntimeException(trim($output['error']));
        }

        $text = $this->textFrom($output);

        if ($text === '') {
            throw new RuntimeException(ServiceUserMessage::emptyTranscriptionResponse('RunPod'));
        }

        return [
            'text' => $text,
            'timestamps' => $this->timestampsFrom($output),
        ];
    }

    private function normalizeBatch(array $payload, array $sourceClips): array
    {
        $status = strtoupper((string) data_get($payload, 'status', ''));

        if (in_array($status, ['FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
            throw new RuntimeException(ServiceUserMessage::transcriptionFailed('RunPod'));
        }

        $output = data_get($payload, 'output', $payload);
        $output = is_array($output) ? $output : ['text' => $output];

        if (is_string($output['error'] ?? null) && trim($output['error']) !== '') {
            throw new RuntimeException(trim($output['error']), str_contains($output['error'], 'Audio is too big') ? 422 : 0);
        }

        $clips = array_values(array_filter(data_get($output, 'clips', []), 'is_array'));

        if ($clips === []) {
            $clips = [[
                ...$output,
                'queue_index' => 0,
                'clip_index' => $sourceClips[0]['clip_index'] ?? null,
                'clip_start_ms' => $sourceClips[0]['clip_start_ms'] ?? null,
                'clip_end_ms' => $sourceClips[0]['clip_end_ms'] ?? null,
            ]];
        }

        return array_map(function (array $clip, int $index) use ($sourceClips): array {
            $normalized = $this->normalize($clip);
            $source = $sourceClips[$index] ?? [];

            return [
                ...$normalized,
                'queue_index' => isset($clip['queue_index']) ? (int) $clip['queue_index'] : $index,
                'clip_index' => isset($clip['clip_index']) ? (int) $clip['clip_index'] : ($source['clip_index'] ?? null),
                'clip_start_ms' => isset($clip['clip_start_ms']) ? (int) $clip['clip_start_ms'] : ($source['clip_start_ms'] ?? null),
                'clip_end_ms' => isset($clip['clip_end_ms']) ? (int) $clip['clip_end_ms'] : ($source['clip_end_ms'] ?? null),
            ];
        }, $clips, array_keys($clips));
    }

    private function batchDurationTooLarge(array $clips): bool
    {
        $totalDurationMs = 0;

        foreach ($clips as $clip) {
            $start = $clip['clip_start_ms'] ?? null;
            $end = $clip['clip_end_ms'] ?? null;

            if (! is_numeric($start) || ! is_numeric($end)) {
                continue;
            }

            $durationMs = max(0, (int) $end - (int) $start);

            if ((int) $end > 20 * 60 * 1000 || $durationMs > 20 * 60 * 1000) {
                return true;
            }

            $totalDurationMs += $durationMs;
        }

        return $totalDurationMs > 20 * 60 * 1000;
    }

    private function textFrom(array $output): string
    {
        foreach (['text', 'transcription', 'transcript', 'result'] as $key) {
            $value = data_get($output, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $segments = data_get($output, 'segments', []);

        if (is_array($segments)) {
            return trim(collect($segments)
                ->filter(fn (mixed $segment): bool => is_array($segment))
                ->pluck('text')
                ->filter(fn (mixed $text): bool => is_string($text) && trim($text) !== '')
                ->implode(' '));
        }

        return '';
    }

    private function timestampsFrom(array $output): array
    {
        $items = data_get($output, 'timestamps')
            ?? data_get($output, 'words')
            ?? data_get($output, 'segments')
            ?? [];

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(
            fn (array $item): array => [
                'text' => trim((string) ($item['text'] ?? $item['word'] ?? '')),
                'start' => $item['start'] ?? $item['start_time'] ?? null,
                'end' => $item['end'] ?? $item['end_time'] ?? null,
                'type' => (string) ($item['type'] ?? (isset($item['word']) ? 'word' : 'segment')),
                'speaker_id' => $item['speaker_id'] ?? $item['speaker'] ?? null,
            ],
            array_filter($items, 'is_array'),
        ));
    }

    private function messageForStatus(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('RunPod'),
            $status === 429 => ServiceUserMessage::providerBusy('RunPod'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('RunPod'),
            default => ServiceUserMessage::transcriptionFailed('RunPod'),
        };
    }

    /**
     * @return array{path: string, name: string, mime_type: string}
     */
    private function audioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        $path = $audio instanceof UploadedFile || $audio instanceof SplFileInfo ? $audio->getRealPath() : $audio;

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $path,
            'name' => $audio instanceof UploadedFile ? ($audio->getClientOriginalName() ?: $audio->getFilename()) : basename($path),
            'mime_type' => $this->mimeType($path),
        ];
    }

    private function mimeType(string $path): string
    {
        $mimeType = function_exists('mime_content_type') ? mime_content_type($path) : false;

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }

    /**
     * @param  array{path: string, name: string, mime_type: string}  $file
     */
    private function storeTemporaryAudio(array $file): string
    {
        $contents = file_get_contents($file['path']);

        if ($contents === false) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'audio';
        $temporaryPath = 'runpod-audio/'.Str::uuid().'.'.$extension;

        Storage::disk('local')->put($temporaryPath, $contents);

        return $temporaryPath;
    }

    private function temporaryAudioUrl(string $temporaryPath): string
    {
        return URL::temporarySignedRoute(
            'runpod.audio.temporary',
            now()->addSeconds((int) config('services.runpod.audio_url_ttl_seconds', 600)),
            ['file' => basename($temporaryPath)],
        );
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
}
