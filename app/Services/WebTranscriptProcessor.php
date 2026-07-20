<?php

namespace App\Services;

use App\Models\Transcript;
use App\Models\TranscriptSection;
use App\Models\UsageRecord;
use App\Models\User;
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

            if ($transcript->fresh()?->status === 'cancelled') {
                return;
            }

            $this->appendLog($transcript, 'processing', 'Finalizing');
            $this->persistTranscriptionResult($transcript, $result);
            $this->recordUsage($transcript);
            $this->appendLog($transcript, 'completed', 'Complete');
        } catch (Throwable $exception) {
            Log::warning('Web transcription through API pipeline failed.', [
                'transcript_id' => $transcript->id,
                'error' => $exception->getMessage(),
            ]);

            $this->fail($transcript, $exception->getMessage() ?: 'Audio upload could not be processed.');
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

        $transcript->forceFill([
            'cleaned_text' => $cleaned,
            'polish_status' => 'complete',
            'polish_error_message' => null,
        ])->save();
        $this->appendLog($transcript, 'polished', 'Transcript polished.');

        $this->usageRecord($transcript)->increment('polish_count');

        return $cleaned;
    }

    public function summarize(Transcript $transcript, string $source): string
    {
        $text = $source === 'cleaned'
            ? trim((string) ($transcript->cleaned_text ?? $transcript->raw_text))
            : trim((string) $transcript->raw_text);

        $result = $this->cleanText(
            $text,
            'Summarize this transcript. Preserve important names, facts, numbers, decisions, and action items.',
            'summarize',
        );
        $summary = trim((string) ($result['text'] ?? ''));

        $transcript->forceFill([
            'summary_text' => $summary,
            'summary_status' => 'complete',
            'summary_error_message' => null,
        ])->save();
        $this->appendLog($transcript, 'summarized', 'Transcript summarized.');

        $this->usageRecord($transcript)->increment('summary_count');

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
        ]);
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
            ),
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => new GroqTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_DEEPSEEK => new DeepSeekTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_CEREBRAS => new CerebrasTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_MISTRAL => new MistralTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_OPENROUTER => new OpenRouterTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_CLOUDFLARE => new CloudflareTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: $this->settings->cloudflareChatCompletionsUrl($provider['metadata']['account_id'] ?? null),
            ),
            default => throw new \InvalidArgumentException('Unsupported text fixer provider.'),
        };
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

        $this->usageRecord($transcript)->increment('seconds_transcribed', $seconds);
    }

    private function usageRecord(Transcript $transcript): UsageRecord
    {
        $user = $transcript->project()->first()?->user()->first();

        if (! $user instanceof User) {
            throw new \RuntimeException('Transcript owner could not be resolved.');
        }

        return app(EntitlementService::class)->usageForCurrentPeriod($user);
    }
}
