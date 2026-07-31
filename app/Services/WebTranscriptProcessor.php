<?php

namespace App\Services;

use App\Models\Transcript;
use App\Models\TranscriptSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebTranscriptProcessor
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly WebApiTranscriptionClient $transcriptionClient,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function transcribe(Transcript $transcript, array $options = []): void
    {
        if ($this->isTerminalStatus($transcript->fresh()?->status)) {
            return;
        }

        $this->appendLog($transcript, 'processing', 'Transcribing');

        try {
            $user = $transcript->project()->first()?->user()->first();

            if (! $user instanceof User) {
                throw new \RuntimeException('Transcript owner could not be resolved.');
            }

            $clips = $this->transcriptionClips($transcript, $options);
            $result = $this->transcriptionClient->transcribe(
                $user,
                $clips,
                is_string($options['language_code'] ?? null) ? $options['language_code'] : null,
            );

            $this->finalizeTranscriptionOnce($transcript, $result);
        } catch (Throwable $exception) {
            Log::error('Web transcription through API pipeline failed.', [
                'transcript_id' => $transcript->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->fail($transcript, 'Audio upload could not be processed.');
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function completeTranscription(Transcript $transcript, array $result): void
    {
        try {
            $this->finalizeTranscriptionOnce($transcript, $result);
        } catch (Throwable $exception) {
            Log::error('Web async transcription finalization failed.', [
                'transcript_id' => $transcript->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->fail($transcript, 'Audio upload could not be processed.');
        }
    }

    public function failTranscription(Transcript $transcript): void
    {
        $this->fail($transcript, 'Audio upload could not be processed.');
    }

    public function polish(Transcript $transcript, string $instruction): string
    {
        $text = $this->sourceText($transcript);
        $user = $transcript->project()->first()?->user()->first();

        if (! $user instanceof User) {
            throw new \RuntimeException('Transcript owner could not be resolved.');
        }

        $result = $this->transcriptionClient->polish($user, $text, [], $instruction, 'polish');
        $cleaned = trim((string) ($result['text'] ?? ''));

        if ($cleaned === '') {
            throw new \RuntimeException('Transcript could not be polished.');
        }

        DB::transaction(function () use ($transcript, $user, $cleaned, $text): void {
            $lockedTranscript = Transcript::query()
                ->whereKey($transcript->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTranscript->polish_status !== 'processing') {
                return;
            }

            $lockedTranscript->forceFill([
                'cleaned_text' => $cleaned,
                'polish_status' => 'complete',
                'polish_error_message' => null,
            ])->save();
            $this->appendLog($lockedTranscript, 'polished', 'Transcript polished.');

            app(EntitlementService::class)->charge($user, 'polish', mb_strlen($text));
        });

        return $cleaned;
    }

    public function summarize(Transcript $transcript, string $source): string
    {
        $text = $source === 'cleaned'
            ? trim((string) ($transcript->cleaned_text ?? $transcript->raw_text))
            : trim((string) $transcript->raw_text);
        $user = $transcript->project()->first()?->user()->first();

        if (! $user instanceof User) {
            throw new \RuntimeException('Transcript owner could not be resolved.');
        }

        $result = $this->cleanText(
            $text,
            'Summarize this transcript. Preserve important names, facts, numbers, decisions, and action items.',
            'summarize',
        );
        $summary = trim((string) ($result['text'] ?? ''));

        DB::transaction(function () use ($transcript, $user, $summary, $text): void {
            $lockedTranscript = Transcript::query()
                ->whereKey($transcript->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTranscript->summary_status !== 'processing') {
                return;
            }

            $lockedTranscript->forceFill([
                'summary_text' => $summary,
                'summary_status' => 'complete',
                'summary_error_message' => null,
            ])->save();
            $this->appendLog($lockedTranscript, 'summarized', 'Transcript summarized.');

            app(EntitlementService::class)->charge($user, 'summarize', mb_strlen($text));
        });

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function appendLog(Transcript $transcript, string $status, string $message, array $context = []): void
    {
        $log = $transcript->processing_log ?? [];
        $log[] = [
            'status' => $status,
            'message' => $message,
            'context' => $context,
            'created_at' => now()->toISOString(),
        ];

        $updates = ['processing_log' => $log];

        if (in_array($status, ['queued', 'processing', 'completed', 'failed', 'cancelled'], true)) {
            $updates['status'] = $status;
        }

        $transcript->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(Transcript $transcript, string $message, array $context = []): void
    {
        $this->appendLog($transcript, 'failed', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finalizeTranscriptionOnce(Transcript $transcript, array $result): bool
    {
        return DB::transaction(function () use ($transcript, $result): bool {
            $lockedTranscript = Transcript::query()
                ->whereKey($transcript->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isTerminalStatus($lockedTranscript->status)) {
                return false;
            }

            $this->appendLog($lockedTranscript, 'processing', 'Finalizing');
            $this->persistTranscriptionResult($lockedTranscript, $result);
            $this->recordUsage($lockedTranscript);
            $this->appendLog($lockedTranscript, 'completed', 'Complete');

            return true;
        });
    }

    private function isTerminalStatus(?string $status): bool
    {
        return in_array($status, ['completed', 'cancelled', 'failed'], true);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    private function transcriptionClips(Transcript $transcript, array $options): array
    {
        $clips = array_values(array_filter($options['clips'] ?? [], 'is_array'));

        if ($clips !== []) {
            return $clips;
        }

        $path = (string) $transcript->audio_path;

        return [[
            'path' => $path,
            'name' => basename($path),
            'clip_index' => $options['clip_index'] ?? 0,
            'clip_start_ms' => $options['clip_start_ms'] ?? 0,
            'clip_end_ms' => $options['clip_end_ms'] ?? max(0, (int) $transcript->duration_seconds * 1000),
            'language_code' => $options['language_code'] ?? null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistTranscriptionResult(Transcript $transcript, array $result): void
    {
        $text = trim((string) ($result['text'] ?? ''));
        $durationMs = (int) ($result['duration_ms'] ?? 0);

        $transcript->forceFill([
            'raw_text' => $text,
            'duration_seconds' => $durationMs > 0
                ? (int) ceil($durationMs / 1000)
                : $transcript->duration_seconds,
        ])->save();

        $transcript->sections()->delete();
        $clips = array_values(array_filter($result['clips'] ?? [], 'is_array'));

        if ($clips === []) {
            $this->createSection($transcript, 0, $text, $result);

            return;
        }

        foreach ($clips as $position => $clip) {
            $this->createSection($transcript, $position, (string) ($clip['text'] ?? ''), $clip);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createSection(Transcript $transcript, int $position, string $text, array $payload): TranscriptSection
    {
        return $transcript->sections()->create([
            'position' => $position,
            'text' => $text,
            'started_at_ms' => is_numeric($payload['clip_start_ms'] ?? null)
                ? (int) $payload['clip_start_ms']
                : null,
            'ended_at_ms' => is_numeric($payload['clip_end_ms'] ?? null)
                ? (int) $payload['clip_end_ms']
                : null,
            'speaker_timestamps' => $this->speakerTimestamps($payload),
        ]);
    }

    /**
     * Keep only the timestamp fields needed to reproduce the desktop
     * speaker-turn export instead of storing complete provider payloads.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{speaker_id: string, text: string}>|null
     */
    private function speakerTimestamps(array $payload): ?array
    {
        $timestamps = collect(is_array($payload['timestamps'] ?? null) ? $payload['timestamps'] : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): array => [
                'speaker_id' => trim((string) ($entry['speaker_id'] ?? $entry['speakerId'] ?? '')),
                'text' => trim((string) ($entry['text'] ?? '')),
            ])
            ->filter(fn (array $entry): bool => $entry['speaker_id'] !== '' && $entry['text'] !== '')
            ->values()
            ->all();

        return $timestamps !== [] ? $timestamps : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanText(string $text, string $instruction, string $task): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new \RuntimeException('There is no transcript text to process.');
        }

        $providers = $this->settings->orderedConnectedProviders('text_fixer');

        if ($providers === []) {
            throw new \RuntimeException('All configured text-fixer providers are unavailable.');
        }

        foreach ($providers as $provider) {
            try {
                return $this->cleanerForProvider($provider)->clean($text, [], [
                    'instructions' => $instruction,
                    'task' => $task,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Web transcript text fixer provider failed.', [
                    'provider' => $provider['provider'] ?? null,
                    'task' => $task,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException('All configured text-fixer providers are unavailable.');
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function cleanerForProvider(array $provider): GeminiTranscriptCleanerService|GroqTranscriptCleanerService|DeepSeekTranscriptCleanerService|CerebrasTranscriptCleanerService|MistralTranscriptCleanerService|OpenRouterTranscriptCleanerService|CloudflareTranscriptCleanerService
    {
        return match ($provider['provider']) {
            AppSettingsService::PROVIDER_GEMINI => new GeminiTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpointTemplate: $this->providerMetadataString($provider, 'endpoint_template'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => new GroqTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_DEEPSEEK => new DeepSeekTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_CEREBRAS => new CerebrasTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            AppSettingsService::PROVIDER_MISTRAL => new MistralTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            AppSettingsService::PROVIDER_OPENROUTER => new OpenRouterTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            AppSettingsService::PROVIDER_CLOUDFLARE => new CloudflareTranscriptCleanerService(
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                maxRetries: $this->providerMetadataInt($provider, 'max_retries'),
            ),
            default => throw new \InvalidArgumentException('Unsupported text fixer provider.'),
        };
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function providerMetadataString(array $provider, string $key): string
    {
        $value = ($provider['metadata'] ?? [])[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Provider [{$provider['provider']}] runtime setting [{$key}] is not configured in API Manager.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function providerMetadataInt(array $provider, string $key): int
    {
        $value = ($provider['metadata'] ?? [])[$key] ?? null;

        if (! is_numeric($value)) {
            throw new \RuntimeException("Provider [{$provider['provider']}] runtime setting [{$key}] is not configured in API Manager.");
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<int, string>
     */
    private function providerModels(array $provider): array
    {
        $models = $provider['models'] ?? null;

        if (! is_array($models) || array_values(array_filter($models, is_string(...))) === []) {
            throw new \RuntimeException("Provider [{$provider['provider']}] model list is not configured in API Manager.");
        }

        return array_values(array_filter($models, is_string(...)));
    }

    private function sourceText(Transcript $transcript): string
    {
        return trim((string) ($transcript->raw_text ?: $transcript->sections()->orderBy('position')->pluck('text')->implode("\n\n")));
    }

    private function recordUsage(Transcript $transcript): void
    {
        $seconds = max(0, (int) $transcript->duration_seconds);

        if ($seconds === 0) {
            return;
        }

        $user = $transcript->project()->first()?->user()->first();

        if (! $user instanceof User) {
            throw new \RuntimeException('Transcript owner could not be resolved.');
        }

        app(EntitlementService::class)->charge($user, $transcript->source === 'live' ? 'live' : 'upload', $seconds);
    }
}
