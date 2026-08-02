<?php

namespace App\Services;

use App\Models\Transcript;
use App\Models\TranscriptSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WebTranscriptProcessor
{
    private const SUMMARY_INSTRUCTIONS = <<<'TEXT'
Create a concise, professional report from this transcript. Organize the report by topic rather than by transcript chunk or timestamp. Start with a short overall summary, then give each distinct topic a clear heading and summarize the important discussion beneath it. Under each topic, include decisions, action items, responsible people or offices, deadlines, dates, numbers, and unresolved issues when they are present. Use readable paragraphs and bullet lists where appropriate, omit empty sections, avoid repetition, and preserve the original meaning and factual details. Format headings as Markdown headings using ## or ###, and format lists as Markdown bullets using -.
TEXT;

    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly WebApiTranscriptionClient $transcriptionClient,
        private readonly ProviderFallbackLogger $providerLogger,
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
                $transcript->source === 'live' ? 'live' : 'upload',
            );

            if ($this->finalizeTranscriptionOnce($transcript, $result)) {
                $this->cleanupTranscriptAudio($transcript->fresh() ?? $transcript);
            }
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
            if ($this->finalizeTranscriptionOnce($transcript, $result)) {
                $this->cleanupTranscriptAudio($transcript->fresh() ?? $transcript);
            }
        } catch (Throwable $exception) {
            Log::error('Web async transcription finalization failed.', [
                'transcript_id' => $transcript->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->fail($transcript, 'Audio upload could not be processed.');
        }
    }

    /**
     * Persist completed async batches while the remaining batches continue.
     *
     * @param  array<string, mixed>  $result
     */
    public function updatePartialTranscription(Transcript $transcript, array $result): void
    {
        try {
            DB::transaction(function () use ($transcript, $result): void {
                $lockedTranscript = Transcript::query()
                    ->whereKey($transcript->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->isTerminalStatus($lockedTranscript->status)) {
                    return;
                }

                $this->persistTranscriptionResult($lockedTranscript, $result, false);

                if ($lockedTranscript->status !== 'processing') {
                    $lockedTranscript->forceFill(['status' => 'processing'])->save();
                }
            });
        } catch (Throwable $exception) {
            Log::error('Web async partial transcription update failed.', [
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

    public function cleanupTranscriptAudio(Transcript $transcript): void
    {
        $paths = [];

        if (is_string($transcript->audio_path) && $transcript->audio_path !== '') {
            $paths[] = $transcript->audio_path;
        }

        foreach ($this->clipPayloads($transcript) as $clip) {
            foreach (['path', 'audio_path'] as $key) {
                $path = $clip[$key] ?? null;

                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }

        $paths = array_values(array_unique($paths));

        if ($paths !== []) {
            Storage::disk('local')->delete($paths);
        }
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

            $history = array_values($lockedTranscript->polish_history ?? []);
            $history[] = $lockedTranscript->cleaned_text;

            $lockedTranscript->forceFill([
                'cleaned_text' => $cleaned,
                'polish_history' => $history,
                'polish_status' => 'complete',
                'polish_error_message' => null,
            ])->save();
            $this->appendLog($lockedTranscript, 'polished', 'Transcript polished.');

            app(EntitlementService::class)->charge($user, 'polish', mb_strlen($text));
        });

        return $cleaned;
    }

    public function undoPolish(Transcript $transcript): Transcript
    {
        return DB::transaction(function () use ($transcript): Transcript {
            $lockedTranscript = Transcript::query()
                ->whereKey($transcript->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTranscript->polish_status === 'processing') {
                throw new \RuntimeException('Wait for polishing to finish before undoing it.');
            }

            if (! filled($lockedTranscript->cleaned_text)) {
                throw new \RuntimeException('There is no polished transcript to undo.');
            }

            $history = array_values($lockedTranscript->polish_history ?? []);
            $previous = $history === [] ? null : array_pop($history);

            $lockedTranscript->forceFill([
                'cleaned_text' => filled($previous) ? (string) $previous : null,
                'polish_history' => $history,
                'polish_status' => filled($previous) ? 'complete' : 'idle',
                'polish_error_message' => null,
            ])->save();
            $this->appendLog($lockedTranscript, 'polish_undone', 'Polish undone.');

            return $lockedTranscript->fresh();
        });
    }

    public function summarize(Transcript $transcript): string
    {
        $text = $this->sourceText($transcript);
        $user = $transcript->project()->first()?->user()->first();

        if (! $user instanceof User) {
            throw new \RuntimeException('Transcript owner could not be resolved.');
        }

        $result = $this->cleanText(
            $text,
            self::SUMMARY_INSTRUCTIONS,
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
        $this->cleanupTranscriptAudio($transcript->fresh() ?? $transcript);
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
     * @return array<int, array<string, mixed>>
     */
    private function clipPayloads(Transcript $transcript): array
    {
        $clips = [];

        foreach (array_values($transcript->processing_log ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            $entryClips = is_array($context['clips'] ?? null) ? $context['clips'] : [];

            foreach ($entryClips as $clip) {
                if (is_array($clip)) {
                    $clips[] = $clip;
                }
            }
        }

        return $clips;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistTranscriptionResult(Transcript $transcript, array $result, bool $updateDuration = true): void
    {
        $text = trim((string) ($result['text'] ?? ''));
        $durationMs = (int) ($result['duration_ms'] ?? 0);

        $transcript->forceFill([
            'raw_text' => $text,
            'duration_seconds' => $updateDuration && $durationMs > 0
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

        foreach ($providers as $position => $provider) {
            try {
                $result = $this->cleanerForProvider($provider)->clean($text, [], [
                    'instructions' => $instruction,
                    'task' => $task,
                ]);
                $this->providerLogger->success('text_fixer', $task, $provider, $position);

                return $result;
            } catch (Throwable $exception) {
                $this->providerLogger->failure('text_fixer', $task, $provider, $position, $exception);
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
                allowedModels: $this->providerModels($provider),
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpointTemplate: $this->providerMetadataString($provider, 'endpoint_template'),
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => new GroqTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->providerMetadataString($provider, 'chat_completions_url'),
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
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
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
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
        return trim((string) (
            $transcript->cleaned_text
            ?: $transcript->raw_text
            ?: $transcript->sections()->orderBy('position')->pluck('text')->implode("\n\n")
        ));
    }
}
