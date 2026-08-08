<?php

namespace App\Http\Controllers\Api\APIController;

use App\Http\Controllers\Controller;
use App\Models\TranscriptionApiRequestLog;
use App\Models\TranscriptionProviderSetting;
use App\Services\Transcription\AppSettingsService;
use App\Services\Transcription\ProviderConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TranscriptionProviderController extends Controller
{
    public function updateTranscriptionProviders(Request $request, AppSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'providers' => ['required', 'array'],
            'providers.*.api_key' => ['nullable', 'string', 'max:12000'],
            'providers.*.model' => ['required', 'string', 'max:100'],
            'providers.*.is_enabled' => ['nullable'],
            'providers.*.setting_id' => ['nullable', 'integer'],
            'providers.*.account_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'providers.*.endpoint_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'providers.*.runsync_url' => ['nullable', 'url', 'max:2048'],
            'providers.*.metadata_json' => ['nullable', 'json'],
        ]);

        $providerCatalog = collect($settings->providerCards());

        foreach ($validated['providers'] as $provider => $data) {
            $settingId = isset($data['setting_id']) ? (int) $data['setting_id'] : null;
            $existingProvider = $settingId
                ? $providerCatalog->first(fn (array $item): bool => $item['setting_id'] === $settingId && $item['provider'] === $provider)
                : $providerCatalog->first(fn (array $item): bool => ! $item['configured']
                    && $item['provider'] === $provider);

            $availableModels = is_array($existingProvider['models'] ?? null)
                ? $existingProvider['models']
                : [];

            if (filled($data['api_key'] ?? null) && $this->supportsCredentialModelDiscovery($provider)) {
                $discovered = $settings->discoverProviderModels($provider, (string) $data['api_key']);

                if ($discovered['models'] === []) {
                    throw ValidationException::withMessages([
                        "providers.$provider.api_key" => 'No compatible models were returned for this API key.',
                    ]);
                }

                $availableModels = $discovered['models'];
            }

            if (! $existingProvider || ! in_array($data['model'], $availableModels, true)) {
                throw ValidationException::withMessages([
                    "providers.$provider" => 'The selected provider is not available.',
                ]);
            }

            if (! $settingId
                && blank($data['api_key'] ?? null)
                && ! ($existingProvider['has_reusable_api_key'] ?? false)) {
                throw ValidationException::withMessages([
                    "providers.$provider.api_key" => 'An API key is required when adding a provider.',
                ]);
            }

            if ($provider === AppSettingsService::PROVIDER_CLOUDFLARE
                && blank($data['account_id'] ?? $existingProvider['metadata']['account_id'] ?? null)) {
                throw ValidationException::withMessages([
                    "providers.$provider.account_id" => 'A Cloudflare Account ID is required.',
                ]);
            }

            if ($provider === AppSettingsService::PROVIDER_RUNPOD
                && blank($data['runsync_url'] ?? $existingProvider['metadata']['runsync_url'] ?? null)
                && blank($data['endpoint_id'] ?? $existingProvider['metadata']['endpoint_id'] ?? null)) {
                throw ValidationException::withMessages([
                    "providers.$provider.endpoint_id" => 'A RunPod Endpoint ID or Runsync URL is required.',
                ]);
            }

            if (filled($data['api_key'] ?? null) && in_array($provider, [
                AppSettingsService::PROVIDER_AZURE_SPEECH,
                AppSettingsService::PROVIDER_GOOGLE_SPEECH,
                AppSettingsService::PROVIDER_AWS_TRANSCRIBE,
            ], true) && ! is_array(json_decode((string) $data['api_key'], true))) {
                throw ValidationException::withMessages([
                    "providers.$provider.api_key" => 'This provider requires a valid credentials JSON document.',
                ]);
            }

            if (filled($data['api_key'] ?? null)) {
                $credential = json_decode((string) $data['api_key'], true);
                $requiredCredentialFields = match ($provider) {
                    AppSettingsService::PROVIDER_AZURE_SPEECH => ['key', 'region'],
                    AppSettingsService::PROVIDER_GOOGLE_SPEECH => ['project_id', 'client_email', 'private_key'],
                    AppSettingsService::PROVIDER_AWS_TRANSCRIBE => ['access_key_id', 'secret_access_key', 'region', 'bucket'],
                    default => [],
                };

                if (collect($requiredCredentialFields)->contains(fn (string $field): bool => blank($credential[$field] ?? null))) {
                    throw ValidationException::withMessages([
                        "providers.$provider.api_key" => 'The credentials JSON is missing one or more required fields.',
                    ]);
                }
            }

            if (TranscriptionProviderSetting::query()
                ->where('provider', $provider)
                ->where('model', $data['model'])
                ->when($settingId, fn ($query) => $query->where('id', '!=', $settingId))
                ->exists()) {
                throw ValidationException::withMessages([
                    "providers.$provider.model" => 'This provider and model combination has already been added.',
                ]);
            }
        }

        $settings->saveProviderSettings($validated['providers']);

        return response()->json([
            'success' => true,
            'message' => 'Transcription provider settings saved successfully!',
            'providers' => $settings->providerCards(),
        ]);
    }

    public function transcriptionProviderModels(Request $request, AppSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'provider' => [
                'required',
                'string',
                Rule::in([
                    AppSettingsService::PROVIDER_GEMINI,
                    AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
                    AppSettingsService::PROVIDER_GROQ_TEXT_FIXER,
                    AppSettingsService::PROVIDER_MISTRAL,
                    AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION,
                    AppSettingsService::PROVIDER_NVIDIA,
                ]),
            ],
            'api_key' => ['nullable', 'string', 'max:12000'],
        ]);
        $provider = (string) $validated['provider'];
        $models = $settings->discoverProviderModels(
            $provider,
            isset($validated['api_key']) ? (string) $validated['api_key'] : null,
        );

        if ($models['models'] === []) {
            throw ValidationException::withMessages([
                'api_key' => 'No compatible models were returned. Check the API key and provider access.',
            ]);
        }

        return response()->json($models);
    }

    public function transcriptionProviderHealth(ProviderConnectionService $connections): JsonResponse
    {
        return response()->json([
            'providers' => $connections->checkAll(),
        ]);
    }

    public function transcriptionProviderLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'in:transcriber,text_fixer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
        ]);

        $operations = $validated['category'] === 'transcriber'
            ? ['transcribe_provider']
            : ['polish_provider', 'summarize_provider', 'chatbot_provider'];

        $paginator = TranscriptionApiRequestLog::query()
            ->select([
                'id',
                'created_at',
                'operation',
                'provider',
                'model',
                'status',
                'http_status',
                'request_summary',
                'error_message',
            ])
            ->whereIn('operation', $operations)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $logs = $paginator->getCollection()
            ->map(function (TranscriptionApiRequestLog $log): array {
                $succeeded = in_array($log->status, ['provider_succeeded', 'fallback_succeeded'], true);

                return [
                    'id' => $log->id,
                    'created_at' => $log->created_at?->toISOString(),
                    'source' => match ($log->operation) {
                        'transcribe_provider' => 'Transcription',
                        'polish_provider' => 'Text polishing',
                        'summarize_provider' => 'Summarization',
                        'chatbot_provider' => 'Chatbot',
                        default => $log->operation,
                    },
                    'provider' => $this->transcriptionProviderName($log->provider),
                    'model' => $log->model,
                    'status' => $log->status,
                    'http_status' => $log->http_status,
                    'fallback_position' => data_get($log->request_summary, 'fallback_position'),
                    'error' => $log->error_message,
                    // Display-ready fields so the table markup stays declarative.
                    'succeeded' => $succeeded,
                    'status_label' => match ($log->status) {
                        'provider_succeeded' => 'Succeeded',
                        'fallback_succeeded' => 'Fallback succeeded',
                        default => 'Failed; fallback continued',
                    },
                    'logged_at' => $log->created_at?->format('M j, Y g:i:s A') ?? 'Unknown time',
                ];
            });

        $pagination = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        // A five-page window centred on the current page.
        $windowStart = max(1, min($paginator->currentPage() - 2, $paginator->lastPage() - 4));

        // `html` carries the table rendered by blade so the dashboard never
        // assembles markup; `logs` and `pagination` keep the original shape.
        return response()->json([
            'html' => view('dashboard.partials.provider-logs', [
                'logs' => $logs->values(),
                'pagination' => $pagination,
                'pageNumbers' => range($windowStart, min($paginator->lastPage(), $windowStart + 4)),
            ])->render(),
            'logs' => $logs->values(),
            'pagination' => $pagination,
        ]);
    }

    private function transcriptionProviderName(?string $provider): string
    {
        return match ($provider) {
            AppSettingsService::PROVIDER_DEEPGRAM => 'Deepgram',
            AppSettingsService::PROVIDER_ELEVENLABS => 'ElevenLabs',
            AppSettingsService::PROVIDER_SPEECHMATICS => 'Speechmatics',
            AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => 'Groq',
            AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION,
            AppSettingsService::PROVIDER_MISTRAL => 'Mistral AI',
            AppSettingsService::PROVIDER_GLADIA => 'Gladia',
            AppSettingsService::PROVIDER_ASSEMBLYAI => 'AssemblyAI',
            AppSettingsService::PROVIDER_AZURE_SPEECH => 'Azure Speech',
            AppSettingsService::PROVIDER_GOOGLE_SPEECH => 'Google Cloud Speech-to-Text',
            AppSettingsService::PROVIDER_AWS_TRANSCRIBE => 'Amazon Transcribe',
            AppSettingsService::PROVIDER_RUNPOD => 'RunPod Cebuano/Bisaya Epoch 1',

            AppSettingsService::PROVIDER_GEMINI => 'Gemini',
            AppSettingsService::PROVIDER_DEEPSEEK => 'DeepSeek',
            AppSettingsService::PROVIDER_CEREBRAS => 'Cerebras',
            AppSettingsService::PROVIDER_OPENROUTER => 'OpenRouter',
            AppSettingsService::PROVIDER_NVIDIA => 'NVIDIA NIM',
            AppSettingsService::PROVIDER_CLOUDFLARE => 'Cloudflare Workers AI',
            default => Str::headline((string) $provider),
        };
    }

    public function reorderTranscriptionProviders(Request $request, AppSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'in:transcriber,text_fixer'],
            'providers' => ['required', 'array'],
            'providers.*' => ['required', 'integer', 'distinct'],
        ]);

        $configuredProviders = collect($settings->providerCards())
            ->where('category', $validated['category'])
            ->where('configured', true)
            ->pluck('setting_id')
            ->sort()
            ->values()
            ->all();
        $submittedProviders = array_map(
            fn (mixed $settingId): int => (int) $settingId,
            $validated['providers'],
        );
        sort($submittedProviders);

        if ($configuredProviders !== $submittedProviders) {
            throw ValidationException::withMessages([
                'providers' => 'The provider order must include every added provider in this group.',
            ]);
        }

        $settings->reorderProviders(
            $validated['category'],
            array_map(fn (mixed $settingId): int => (int) $settingId, $validated['providers']),
        );

        return response()->json([
            'success' => true,
            'message' => 'Provider fallback order updated successfully!',
            'providers' => $settings->providerCards(),
        ]);
    }

    private function supportsCredentialModelDiscovery(string $provider): bool
    {
        return in_array($provider, [
            AppSettingsService::PROVIDER_GEMINI,
            AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER,
            AppSettingsService::PROVIDER_MISTRAL,
            AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION,
            AppSettingsService::PROVIDER_NVIDIA,
        ], true);
    }
}
