<?php

namespace App\Services;

use App\Models\TranscriptionProviderSetting;
use Illuminate\Support\Facades\DB;

class AppSettingsService
{
    private const RUNPOD_API_BASE_URL = 'https://api.runpod.ai/v2';

    public const PUBLIC_PROVIDER_ID = 'aims_server';

    public const PUBLIC_PROVIDER_NAME = 'AIMS Server';

    public const PUBLIC_MODEL = 'Free-Model-Fast';

    public const PROVIDER_DEEPGRAM = 'deepgram';

    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const PROVIDER_SPEECHMATICS = 'speechmatics';

    public const PROVIDER_GEMINI = 'gemini';

    public const PROVIDER_GROQ_TRANSCRIPTION = 'groq_transcription';

    public const PROVIDER_GLADIA = 'gladia';

    public const PROVIDER_ASSEMBLYAI = 'assemblyai';

    public const PROVIDER_AZURE_SPEECH = 'azure_speech';

    public const PROVIDER_GOOGLE_SPEECH = 'google_speech';

    public const PROVIDER_AWS_TRANSCRIBE = 'aws_transcribe';

    public const PROVIDER_RUNPOD = 'runpod';

    public const PROVIDER_GROQ_TEXT_FIXER = 'groq_text_fixer';

    public const PROVIDER_DEEPSEEK = 'deepseek';

    public const PROVIDER_CEREBRAS = 'cerebras';

    public const PROVIDER_MISTRAL = 'mistral';

    public const PROVIDER_OPENROUTER = 'openrouter';

    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    public const DEEPGRAM_MODEL_NOVA_3 = DeepgramSpeechToTextService::MODEL_NOVA_3;

    public const ELEVENLABS_MODEL_SCRIBE_V2 = ElevenLabsSpeechToTextService::MODEL_SCRIBE_V2;

    public const GEMINI_MODEL_FLASH_LITE = 'gemini-3.1-flash-lite';

    public function providerCards(): array
    {
        $definitions = $this->providerDefinitions();
        $definitionPositions = array_flip(array_keys($definitions));
        $settings = TranscriptionProviderSetting::query()
            ->whereIn('provider', array_keys($definitions))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('provider');
        $cards = [];

        foreach ($definitions as $providerId => $definition) {
            $providerSettings = $settings->get($providerId, collect());
            $usedModels = $providerSettings
                ->map(fn (TranscriptionProviderSetting $setting): string => $this->validModelForDefinition($definition, $setting->model))
                ->all();
            $unusedModels = array_values(array_diff($definition['models'], $usedModels));
            $reusableApiKey = $providerSettings
                ->first(fn (TranscriptionProviderSetting $setting): bool => filled($setting->api_key))
                ?->api_key;

            foreach ($providerSettings as $setting) {
                $model = $this->validModelForDefinition($definition, $setting->model);
                $metadata = $this->metadataWithDefaults($providerId, $setting->metadata ?? []);

                $selectableModels = array_values(array_unique([
                    $model,
                    ...$unusedModels,
                ]));

                $cards[] = array_merge($definition, [
                    'setting_id' => $setting->id,
                    'integration_key' => 'setting_'.$setting->id,
                    'models' => $selectableModels,
                    'model' => $model,
                    'model_label' => $definition['model_labels'][$model] ?? $model,
                    'is_enabled' => $setting->is_enabled,
                    'configured' => filled($setting->api_key) && $this->providerHasRequiredMetadata($providerId, $metadata),
                    'masked_api_key' => $this->maskKey($setting->api_key),
                    'has_reusable_api_key' => filled($setting->api_key),
                    'metadata' => $metadata,
                    'sort_order' => $setting->sort_order,
                ]);
            }

            if ($unusedModels !== []) {
                $model = $unusedModels[0];
                $cards[] = array_merge($definition, [
                    'setting_id' => null,
                    'integration_key' => 'available_'.$providerId,
                    'models' => $unusedModels,
                    'model' => $model,
                    'model_label' => $definition['model_labels'][$model] ?? $model,
                    'is_enabled' => true,
                    'configured' => false,
                    'masked_api_key' => $this->maskKey($reusableApiKey),
                    'has_reusable_api_key' => filled($reusableApiKey),
                    'metadata' => $this->metadataWithDefaults($providerId),
                    'sort_order' => PHP_INT_MAX,
                ]);
            }
        }

        usort($cards, function (array $left, array $right) use ($definitionPositions): int {
            if ($left['category'] !== $right['category']) {
                return $definitionPositions[$left['provider']] <=> $definitionPositions[$right['provider']];
            }

            return ($left['sort_order'] <=> $right['sort_order'])
                ?: ($definitionPositions[$left['provider']] <=> $definitionPositions[$right['provider']]);
        });

        return $cards;
    }

    public function apiProviderCapabilities(): array
    {
        return array_map(function (array $provider): array {
            $models = array_map(function (string $model) use ($provider): array {
                $capability = [
                    'id' => $model,
                    'label' => $provider['model_labels'][$model] ?? $model,
                ];

                if ($provider['category'] === 'transcriber') {
                    $capability['language_code_parameter'] = 'language_code';
                    $capability['default_language_code'] = $this->defaultLanguageCode($provider['provider'], $model);
                    $capability['language_code_required'] = $this->languageCodeRequired($provider['provider'], $model);
                    $capability['accepts_custom_language_code'] = in_array($provider['provider'], [
                        self::PROVIDER_ELEVENLABS,
                        self::PROVIDER_GROQ_TRANSCRIPTION,
                        self::PROVIDER_GLADIA,
                        self::PROVIDER_ASSEMBLYAI,
                        self::PROVIDER_AZURE_SPEECH,
                        self::PROVIDER_GOOGLE_SPEECH,
                        self::PROVIDER_AWS_TRANSCRIBE,
                        self::PROVIDER_RUNPOD,
                    ], true);
                    $capability['languages'] = $this->languageOptions($provider['provider'], $model);
                }

                return $capability;
            }, $provider['models']);

            return [
                'provider' => $provider['provider'],
                'name' => $provider['name'],
                'purpose' => $provider['purpose'],
                'category' => $provider['category'],
                'configured' => $provider['configured'],
                'enabled' => $provider['is_enabled'],
                'connected' => $provider['configured'] && $provider['is_enabled'],
                'sort_order' => $provider['sort_order'],
                'model' => $provider['model'],
                'models' => array_values($models),
            ];
        }, $this->providerCards());
    }

    public function publicProviderCapability(string $category): array
    {
        $providers = array_values(array_filter(
            $this->apiProviderCapabilities(),
            fn (array $provider): bool => $provider['category'] === $category,
        ));
        $connectedProviders = array_values(array_filter(
            $providers,
            fn (array $provider): bool => $provider['connected'],
        ));
        $primaryProvider = $connectedProviders[0] ?? null;
        $publicModel = [
            'id' => self::PUBLIC_MODEL,
            'label' => self::PUBLIC_MODEL,
        ];

        if ($category === 'transcriber') {
            $primaryModel = collect($primaryProvider['models'] ?? [])
                ->firstWhere('id', $primaryProvider['model'] ?? null)
                ?? (($primaryProvider['models'] ?? [])[0] ?? []);

            foreach ([
                'language_code_parameter',
                'default_language_code',
                'language_code_required',
                'accepts_custom_language_code',
                'languages',
            ] as $field) {
                if (array_key_exists($field, $primaryModel)) {
                    $publicModel[$field] = $primaryModel[$field];
                }
            }

            $publicModel += [
                'language_code_parameter' => 'language_code',
                'default_language_code' => null,
                'language_code_required' => false,
                'accepts_custom_language_code' => true,
                'languages' => [['code' => 'auto', 'label' => 'Automatic detection']],
            ];
        }

        return [
            'provider' => self::PUBLIC_PROVIDER_ID,
            'name' => self::PUBLIC_PROVIDER_NAME,
            'purpose' => $category === 'transcriber' ? 'Server-managed speech to text' : 'Server-managed transcript polishing',
            'category' => $category,
            'configured' => collect($providers)->contains(fn (array $provider): bool => $provider['configured']),
            'enabled' => $connectedProviders !== [],
            'connected' => $connectedProviders !== [],
            'sort_order' => 0,
            'model' => self::PUBLIC_MODEL,
            'models' => [$publicModel],
        ];
    }

    public function saveProviderSettings(array $providers): void
    {
        foreach ($providers as $provider => $data) {
            if (! array_key_exists($provider, $this->providerDefinitions())) {
                continue;
            }

            $definition = $this->providerDefinitions()[$provider];
            $model = $this->validModelForDefinition($definition, $data['model'] ?? null);

            $values = [
                'model' => $model,
                'is_enabled' => isset($data['is_enabled']),
            ];

            $setting = isset($data['setting_id'])
                ? TranscriptionProviderSetting::query()
                    ->whereKey($data['setting_id'])
                    ->where('provider', $provider)
                    ->firstOrFail()
                : new TranscriptionProviderSetting(['provider' => $provider]);

            if (! $setting->exists) {
                $values['sort_order'] = $this->nextSortOrder($definition['category']);
            }

            $apiKey = trim((string) ($data['api_key'] ?? ''));

            if ($apiKey !== '') {
                $values['api_key'] = $apiKey;
            } elseif (! $setting->exists) {
                $values['api_key'] = $this->existingApiKey($provider);
            }

            if ($provider === self::PROVIDER_CLOUDFLARE) {
                $values['metadata'] = [
                    'account_id' => trim((string) ($data['account_id'] ?? $setting->metadata['account_id'] ?? '')),
                ];
            }

            if ($provider === self::PROVIDER_RUNPOD) {
                $values['metadata'] = [
                    'endpoint_id' => trim((string) ($data['endpoint_id'] ?? $setting->metadata['endpoint_id'] ?? '')),
                    'runsync_url' => trim((string) ($data['runsync_url'] ?? $setting->metadata['runsync_url'] ?? '')),
                ];
            }

            $setting->fill($values)->save();
        }
    }

    public function orderedConnectedProviders(string $category): array
    {
        $providers = array_values(array_filter(
            $this->providerCards(),
            fn (array $provider): bool => $provider['category'] === $category
                && $provider['configured']
                && $provider['is_enabled'],
        ));
        $settings = TranscriptionProviderSetting::query()
            ->whereIn('id', array_column($providers, 'setting_id'))
            ->get()
            ->keyBy('id');

        return array_map(function (array $provider) use ($settings): array {
            $provider['api_key'] = $settings->get($provider['setting_id'])?->api_key;

            return $provider;
        }, $providers);
    }

    public function reorderProviders(string $category, array $settingIds): void
    {
        $allowedProviders = $this->providerIdsForCategory($category);

        DB::transaction(function () use ($allowedProviders, $settingIds): void {
            foreach (array_values($settingIds) as $sortOrder => $settingId) {
                TranscriptionProviderSetting::query()
                    ->whereKey($settingId)
                    ->whereIn('provider', $allowedProviders)
                    ->update(['sort_order' => $sortOrder]);
            }
        });
    }

    public function deepgramApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_DEEPGRAM);
    }

    public function deepgramModel(): string
    {
        return self::DEEPGRAM_MODEL_NOVA_3;
    }

    public function elevenLabsApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_ELEVENLABS);
    }

    public function elevenLabsModel(): string
    {
        return self::ELEVENLABS_MODEL_SCRIBE_V2;
    }

    public function speechmaticsApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_SPEECHMATICS);
    }

    public function speechmaticsModel(): string
    {
        return $this->validModelForDefinition(
            $this->providerDefinitions()[self::PROVIDER_SPEECHMATICS],
            $this->model(self::PROVIDER_SPEECHMATICS, SpeechmaticsSpeechToTextService::MODEL_MELIA_1),
        );
    }

    public function geminiApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_GEMINI);
    }

    public function geminiModel(): string
    {
        return self::GEMINI_MODEL_FLASH_LITE;
    }

    public function geminiTimeout(): int
    {
        return (int) config('services.gemini.timeout', 120);
    }

    public function geminiMaxRetries(): int
    {
        return (int) config('services.gemini.max_retries', 3);
    }

    public function groqTranscriptionApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_GROQ_TRANSCRIPTION);
    }

    public function groqTranscriptionModel(): string
    {
        return $this->validModelForDefinition(
            $this->providerDefinitions()[self::PROVIDER_GROQ_TRANSCRIPTION],
            $this->model(self::PROVIDER_GROQ_TRANSCRIPTION, GroqSpeechToTextService::MODEL_WHISPER_LARGE_V3),
        );
    }

    public function groqTextFixerApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_GROQ_TEXT_FIXER);
    }

    public function groqTextFixerModel(): string
    {
        return $this->validModelForDefinition(
            $this->providerDefinitions()[self::PROVIDER_GROQ_TEXT_FIXER],
            $this->model(self::PROVIDER_GROQ_TEXT_FIXER, GroqTranscriptCleanerService::MODEL_LLAMA_4_SCOUT),
        );
    }

    public function groqTimeout(): int
    {
        return (int) config('services.groq.timeout', 120);
    }

    public function groqMaxRetries(): int
    {
        return (int) config('services.groq.max_retries', 3);
    }

    public function deepSeekApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_DEEPSEEK);
    }

    public function deepSeekModel(): string
    {
        return $this->validModelForDefinition(
            $this->providerDefinitions()[self::PROVIDER_DEEPSEEK],
            $this->model(self::PROVIDER_DEEPSEEK, DeepSeekTranscriptCleanerService::MODEL_V4_FLASH),
        );
    }

    public function deepSeekTimeout(): int
    {
        return (int) config('services.deepseek.timeout', 120);
    }

    public function deepSeekMaxRetries(): int
    {
        return (int) config('services.deepseek.max_retries', 3);
    }

    public function cerebrasApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_CEREBRAS);
    }

    public function cerebrasModel(): string
    {
        return $this->providerDefinitionModel(self::PROVIDER_CEREBRAS, CerebrasTranscriptCleanerService::MODEL_GPT_OSS_120B);
    }

    public function cerebrasTimeout(): int
    {
        return (int) config('services.cerebras.timeout', 120);
    }

    public function cerebrasMaxRetries(): int
    {
        return (int) config('services.cerebras.max_retries', 3);
    }

    public function mistralApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_MISTRAL);
    }

    public function mistralModel(): string
    {
        return $this->providerDefinitionModel(self::PROVIDER_MISTRAL, MistralTranscriptCleanerService::MODEL_SMALL_2603);
    }

    public function mistralTimeout(): int
    {
        return (int) config('services.mistral.timeout', 120);
    }

    public function mistralMaxRetries(): int
    {
        return (int) config('services.mistral.max_retries', 3);
    }

    public function openRouterApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_OPENROUTER);
    }

    public function openRouterModel(): string
    {
        return $this->providerDefinitionModel(self::PROVIDER_OPENROUTER, OpenRouterTranscriptCleanerService::MODEL_GEMMA_3_12B_FREE);
    }

    public function openRouterTimeout(): int
    {
        return (int) config('services.openrouter.timeout', 120);
    }

    public function openRouterMaxRetries(): int
    {
        return (int) config('services.openrouter.max_retries', 3);
    }

    public function cloudflareApiKey(): ?string
    {
        return $this->apiKey(self::PROVIDER_CLOUDFLARE);
    }

    public function cloudflareModel(): string
    {
        return $this->providerDefinitionModel(self::PROVIDER_CLOUDFLARE, CloudflareTranscriptCleanerService::MODEL_GLM_4_7_FLASH);
    }

    public function cloudflareTimeout(): int
    {
        return (int) config('services.cloudflare.timeout', 120);
    }

    public function cloudflareMaxRetries(): int
    {
        return (int) config('services.cloudflare.max_retries', 3);
    }

    public function cloudflareChatCompletionsUrl(?string $accountId = null): string
    {
        return $this->cloudflareAccountUrl('ai/v1/chat/completions', $accountId);
    }

    public function cloudflareModelsUrl(?string $accountId = null): string
    {
        return $this->cloudflareAccountUrl('ai/models/search', $accountId);
    }

    public function providerModel(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_DEEPGRAM => $this->deepgramModel(),
            self::PROVIDER_ELEVENLABS => $this->elevenLabsModel(),
            self::PROVIDER_SPEECHMATICS => $this->speechmaticsModel(),
            self::PROVIDER_GEMINI => $this->geminiModel(),
            self::PROVIDER_GROQ_TRANSCRIPTION => $this->groqTranscriptionModel(),
            self::PROVIDER_GLADIA => GladiaSpeechToTextService::MODEL_SOLARIA,
            self::PROVIDER_ASSEMBLYAI => $this->providerDefinitionModel(self::PROVIDER_ASSEMBLYAI, AssemblyAiSpeechToTextService::MODEL_UNIVERSAL_2),
            self::PROVIDER_AZURE_SPEECH => AzureSpeechToTextService::MODEL_FAST_TRANSCRIPTION,
            self::PROVIDER_GOOGLE_SPEECH => GoogleCloudSpeechToTextService::MODEL_CHIRP_3,
            self::PROVIDER_AWS_TRANSCRIBE => AwsTranscribeSpeechToTextService::MODEL_STANDARD,
            self::PROVIDER_RUNPOD => RunPodSpeechToTextService::MODEL_SERVERLESS_TRANSCRIPTOR,
            self::PROVIDER_GROQ_TEXT_FIXER => $this->groqTextFixerModel(),
            self::PROVIDER_DEEPSEEK => $this->deepSeekModel(),
            self::PROVIDER_CEREBRAS => $this->cerebrasModel(),
            self::PROVIDER_MISTRAL => $this->mistralModel(),
            self::PROVIDER_OPENROUTER => $this->openRouterModel(),
            self::PROVIDER_CLOUDFLARE => $this->cloudflareModel(),
            default => '',
        };
    }

    private function apiKey(string $provider): ?string
    {
        $setting = TranscriptionProviderSetting::query()
            ->where('provider', $provider)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $setting?->api_key;
    }

    private function existingApiKey(string $provider): ?string
    {
        $setting = TranscriptionProviderSetting::query()
            ->where('provider', $provider)
            ->whereNotNull('api_key')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return filled($setting?->api_key) ? $setting->api_key : null;
    }

    private function model(string $provider, string $fallback): string
    {
        $setting = TranscriptionProviderSetting::query()
            ->where('provider', $provider)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $setting?->model ?: $fallback;
    }

    private function validModelForDefinition(array $definition, mixed $candidate): string
    {
        $model = is_string($candidate) && trim($candidate) !== ''
            ? trim($candidate)
            : $definition['default_model'];

        if (! in_array($model, $definition['models'], true)) {
            return $definition['default_model'];
        }

        return $model;
    }

    private function providerDefinitions(): array
    {
        return [
            self::PROVIDER_DEEPGRAM => [
                'provider' => self::PROVIDER_DEEPGRAM,
                'name' => 'Deepgram',
                'endpoint' => config('services.deepgram.listen_url'),
                'default_model' => self::DEEPGRAM_MODEL_NOVA_3,
                'models' => [self::DEEPGRAM_MODEL_NOVA_3],
                'model_labels' => [
                    self::DEEPGRAM_MODEL_NOVA_3 => 'Nova-3',
                ],
                'api_key_url' => 'https://console.deepgram.com/',
                'purpose' => 'Speech to text',
                'category' => 'transcriber',
            ],
            self::PROVIDER_ELEVENLABS => [
                'provider' => self::PROVIDER_ELEVENLABS,
                'name' => 'ElevenLabs',
                'endpoint' => config('services.elevenlabs.speech_to_text_url'),
                'default_model' => self::ELEVENLABS_MODEL_SCRIBE_V2,
                'models' => [self::ELEVENLABS_MODEL_SCRIBE_V2],
                'model_labels' => [
                    self::ELEVENLABS_MODEL_SCRIBE_V2 => 'scribe_v2',
                ],
                'api_key_url' => 'https://elevenlabs.io/app/settings/api-keys',
                'purpose' => 'Speech to text',
                'category' => 'transcriber',
            ],
            self::PROVIDER_SPEECHMATICS => [
                'provider' => self::PROVIDER_SPEECHMATICS,
                'name' => 'Speechmatics',
                'endpoint' => rtrim((string) config('services.speechmatics.base_url'), '/').'/jobs',
                'default_model' => SpeechmaticsSpeechToTextService::MODEL_MELIA_1,
                'models' => config('services.speechmatics.speech_to_text_models', [SpeechmaticsSpeechToTextService::MODEL_MELIA_1, SpeechmaticsSpeechToTextService::MODEL_ENHANCED]),
                'model_labels' => [
                    SpeechmaticsSpeechToTextService::MODEL_MELIA_1 => 'melia-1',
                    SpeechmaticsSpeechToTextService::MODEL_ENHANCED => 'enhanced',
                ],
                'api_key_url' => 'https://portal.speechmatics.com/',
                'purpose' => 'Speech to text',
                'category' => 'transcriber',
            ],
            self::PROVIDER_GROQ_TRANSCRIPTION => [
                'provider' => self::PROVIDER_GROQ_TRANSCRIPTION,
                'name' => 'Groq',
                'endpoint' => config('services.groq.transcription_url'),
                'default_model' => GroqSpeechToTextService::MODEL_WHISPER_LARGE_V3,
                'models' => [
                    GroqSpeechToTextService::MODEL_WHISPER_LARGE_V3,
                    GroqSpeechToTextService::MODEL_WHISPER_LARGE_V3_TURBO,
                ],
                'model_labels' => [
                    GroqSpeechToTextService::MODEL_WHISPER_LARGE_V3 => 'Whisper Large V3',
                    GroqSpeechToTextService::MODEL_WHISPER_LARGE_V3_TURBO => 'Whisper Large V3 Turbo',
                ],
                'api_key_url' => 'https://console.groq.com/keys',
                'purpose' => 'Speech to text',
                'category' => 'transcriber',
            ],
            self::PROVIDER_GLADIA => [
                'provider' => self::PROVIDER_GLADIA,
                'name' => 'Gladia',
                'endpoint' => rtrim((string) config('services.gladia.base_url'), '/').'/pre-recorded',
                'default_model' => GladiaSpeechToTextService::MODEL_SOLARIA,
                'models' => [GladiaSpeechToTextService::MODEL_SOLARIA],
                'model_labels' => [GladiaSpeechToTextService::MODEL_SOLARIA => 'Solaria'],
                'api_key_url' => 'https://app.gladia.io/',
                'purpose' => 'Multilingual speech to text',
                'category' => 'transcriber',
            ],
            self::PROVIDER_ASSEMBLYAI => [
                'provider' => self::PROVIDER_ASSEMBLYAI,
                'name' => 'AssemblyAI',
                'endpoint' => rtrim((string) config('services.assemblyai.base_url'), '/').'/transcript',
                'default_model' => AssemblyAiSpeechToTextService::MODEL_UNIVERSAL_2,
                'models' => [AssemblyAiSpeechToTextService::MODEL_UNIVERSAL_2, AssemblyAiSpeechToTextService::MODEL_UNIVERSAL_3_PRO],
                'model_labels' => [
                    AssemblyAiSpeechToTextService::MODEL_UNIVERSAL_2 => 'Universal-2 (99 languages)',
                    AssemblyAiSpeechToTextService::MODEL_UNIVERSAL_3_PRO => 'Universal-3 Pro (highest accuracy)',
                ],
                'api_key_url' => 'https://www.assemblyai.com/dashboard/',
                'purpose' => 'Speech to text',
                'category' => 'transcriber',
            ],
            self::PROVIDER_AZURE_SPEECH => [
                'provider' => self::PROVIDER_AZURE_SPEECH,
                'name' => 'Azure Speech',
                'endpoint' => 'Azure regional Fast Transcription API',
                'default_model' => AzureSpeechToTextService::MODEL_FAST_TRANSCRIPTION,
                'models' => [AzureSpeechToTextService::MODEL_FAST_TRANSCRIPTION],
                'model_labels' => [AzureSpeechToTextService::MODEL_FAST_TRANSCRIPTION => 'Fast Transcription'],
                'api_key_url' => 'https://portal.azure.com/',
                'purpose' => 'Fast speech to text',
                'category' => 'transcriber',
                'credential_label' => 'Credentials JSON',
                'credential_placeholder' => '{"key":"...","region":"eastus"}',
                'credential_help' => 'Paste JSON containing your Azure Speech key and region.',
            ],
            self::PROVIDER_GOOGLE_SPEECH => [
                'provider' => self::PROVIDER_GOOGLE_SPEECH,
                'name' => 'Google Cloud Speech-to-Text',
                'endpoint' => config('services.google_speech.base_url'),
                'default_model' => GoogleCloudSpeechToTextService::MODEL_CHIRP_3,
                'models' => [GoogleCloudSpeechToTextService::MODEL_CHIRP_3],
                'model_labels' => [GoogleCloudSpeechToTextService::MODEL_CHIRP_3 => 'Chirp 3'],
                'api_key_url' => 'https://console.cloud.google.com/iam-admin/serviceaccounts',
                'purpose' => 'Multilingual speech to text',
                'category' => 'transcriber',
                'credential_label' => 'Service Account JSON',
                'credential_placeholder' => 'Paste the complete Google service-account JSON',
                'credential_help' => 'The encrypted credential must include project_id, client_email, and private_key.',
            ],
            self::PROVIDER_AWS_TRANSCRIBE => [
                'provider' => self::PROVIDER_AWS_TRANSCRIBE,
                'name' => 'Amazon Transcribe',
                'endpoint' => 'AWS Transcribe and S3',
                'default_model' => AwsTranscribeSpeechToTextService::MODEL_STANDARD,
                'models' => [AwsTranscribeSpeechToTextService::MODEL_STANDARD],
                'model_labels' => [AwsTranscribeSpeechToTextService::MODEL_STANDARD => 'Standard'],
                'api_key_url' => 'https://console.aws.amazon.com/iam/',
                'purpose' => 'Batch speech to text',
                'category' => 'transcriber',
                'credential_label' => 'Credentials JSON',
                'credential_placeholder' => '{"access_key_id":"...","secret_access_key":"...","region":"ap-southeast-1","bucket":"..."}',
                'credential_help' => 'Paste encrypted AWS credentials plus the S3 bucket used for temporary audio.',
            ],
            self::PROVIDER_RUNPOD => [
                'provider' => self::PROVIDER_RUNPOD,
                'name' => 'RunPod Cebuano/Bisaya Epoch 1',
                'endpoint' => $this->runPodEndpointUrl() ?: 'https://api.runpod.ai/v2/{endpoint_id}/runsync',
                'default_model' => RunPodSpeechToTextService::MODEL_SERVERLESS_TRANSCRIPTOR,
                'models' => [RunPodSpeechToTextService::MODEL_SERVERLESS_TRANSCRIPTOR],
                'model_labels' => [
                    RunPodSpeechToTextService::MODEL_SERVERLESS_TRANSCRIPTOR => 'Cebuano/Bisaya Epoch 1 CT2',
                ],
                'api_key_url' => 'https://console.runpod.io/user/settings',
                'purpose' => 'Serverless speech to text',
                'category' => 'transcriber',
                'credential_label' => 'RunPod API Key',
                'credential_placeholder' => 'Paste RunPod API key',
                'credential_help' => 'RunPod Serverless also requires an Endpoint ID or full /runsync URL.',
                'requires_runpod_endpoint' => true,
            ],
            self::PROVIDER_GEMINI => [
                'provider' => self::PROVIDER_GEMINI,
                'name' => 'Gemini',
                'endpoint' => rtrim((string) config('services.gemini.base_url'), '/').'/models',
                'default_model' => self::GEMINI_MODEL_FLASH_LITE,
                'models' => [self::GEMINI_MODEL_FLASH_LITE],
                'model_labels' => [
                    self::GEMINI_MODEL_FLASH_LITE => self::GEMINI_MODEL_FLASH_LITE,
                ],
                'api_key_url' => 'https://aistudio.google.com/app/apikey',
                'purpose' => 'Transcript polishing',
                'category' => 'text_fixer',
            ],
            self::PROVIDER_GROQ_TEXT_FIXER => [
                'provider' => self::PROVIDER_GROQ_TEXT_FIXER,
                'name' => 'Groq',
                'endpoint' => config('services.groq.chat_completions_url'),
                'default_model' => GroqTranscriptCleanerService::MODEL_LLAMA_4_SCOUT,
                'models' => [
                    GroqTranscriptCleanerService::MODEL_LLAMA_4_SCOUT,
                    GroqTranscriptCleanerService::MODEL_QWEN_3_32B,
                ],
                'model_labels' => [
                    GroqTranscriptCleanerService::MODEL_LLAMA_4_SCOUT => 'Llama 4 Scout 17B',
                    GroqTranscriptCleanerService::MODEL_QWEN_3_32B => 'Qwen 3 32B',
                ],
                'api_key_url' => 'https://console.groq.com/keys',
                'purpose' => 'Transcript polishing',
                'category' => 'text_fixer',
            ],
            self::PROVIDER_DEEPSEEK => [
                'provider' => self::PROVIDER_DEEPSEEK,
                'name' => 'DeepSeek',
                'endpoint' => config('services.deepseek.chat_completions_url'),
                'default_model' => DeepSeekTranscriptCleanerService::MODEL_V4_FLASH,
                'models' => [DeepSeekTranscriptCleanerService::MODEL_V4_FLASH],
                'model_labels' => [
                    DeepSeekTranscriptCleanerService::MODEL_V4_FLASH => 'DeepSeek V4 Flash',
                ],
                'api_key_url' => 'https://platform.deepseek.com/api_keys',
                'purpose' => 'Transcript polishing',
                'category' => 'text_fixer',
            ],
            self::PROVIDER_CEREBRAS => [
                'provider' => self::PROVIDER_CEREBRAS,
                'name' => 'Cerebras',
                'endpoint' => config('services.cerebras.chat_completions_url'),
                'default_model' => CerebrasTranscriptCleanerService::MODEL_GPT_OSS_120B,
                'models' => [CerebrasTranscriptCleanerService::MODEL_GPT_OSS_120B],
                'model_labels' => [
                    CerebrasTranscriptCleanerService::MODEL_GPT_OSS_120B => 'GPT-OSS 120B (Low Reasoning)',
                ],
                'api_key_url' => 'https://cloud.cerebras.ai/platform/',
                'purpose' => 'Transcript polishing',
                'category' => 'text_fixer',
            ],
            self::PROVIDER_MISTRAL => [
                'provider' => self::PROVIDER_MISTRAL,
                'name' => 'Mistral AI',
                'endpoint' => config('services.mistral.chat_completions_url'),
                'default_model' => MistralTranscriptCleanerService::MODEL_SMALL_2603,
                'models' => [MistralTranscriptCleanerService::MODEL_SMALL_2603],
                'model_labels' => [
                    MistralTranscriptCleanerService::MODEL_SMALL_2603 => 'Mistral Small 4',
                ],
                'api_key_url' => 'https://console.mistral.ai/api-keys/',
                'purpose' => 'Transcript polishing',
                'category' => 'text_fixer',
            ],
            self::PROVIDER_OPENROUTER => [
                'provider' => self::PROVIDER_OPENROUTER,
                'name' => 'OpenRouter',
                'endpoint' => config('services.openrouter.chat_completions_url'),
                'default_model' => OpenRouterTranscriptCleanerService::MODEL_GEMMA_3_12B_FREE,
                'models' => [OpenRouterTranscriptCleanerService::MODEL_GEMMA_3_12B_FREE],
                'model_labels' => [
                    OpenRouterTranscriptCleanerService::MODEL_GEMMA_3_12B_FREE => 'Gemma 3 12B (Free)',
                ],
                'api_key_url' => 'https://openrouter.ai/settings/keys',
                'purpose' => 'Transcript polishing',
                'category' => 'text_fixer',
            ],
            self::PROVIDER_CLOUDFLARE => [
                'provider' => self::PROVIDER_CLOUDFLARE,
                'name' => 'Cloudflare Workers AI',
                'endpoint' => $this->cloudflareChatCompletionsUrl(),
                'default_model' => CloudflareTranscriptCleanerService::MODEL_GLM_4_7_FLASH,
                'models' => [CloudflareTranscriptCleanerService::MODEL_GLM_4_7_FLASH],
                'model_labels' => [
                    CloudflareTranscriptCleanerService::MODEL_GLM_4_7_FLASH => 'GLM-4.7 Flash',
                ],
                'api_key_url' => 'https://dash.cloudflare.com/profile/api-tokens',
                'purpose' => 'Transcript polishing (requires Account ID)',
                'category' => 'text_fixer',
                'requires_account_id' => true,
            ],
        ];
    }

    private function providerDefinitionModel(string $provider, string $fallback): string
    {
        return $this->validModelForDefinition(
            $this->providerDefinitions()[$provider],
            $this->model($provider, $fallback),
        );
    }

    private function cloudflareAccountUrl(string $path, ?string $accountId = null): string
    {
        $accountId = trim((string) ($accountId ?: config('services.cloudflare.account_id')));

        if ($accountId === '') {
            return '';
        }

        return rtrim((string) config('services.cloudflare.base_url'), '/')
            .'/'.rawurlencode($accountId).'/'.ltrim($path, '/');
    }

    private function metadataWithDefaults(string $providerId, array $metadata = []): array
    {
        if ($providerId === self::PROVIDER_CLOUDFLARE && blank($metadata['account_id'] ?? null)) {
            $metadata['account_id'] = (string) config('services.cloudflare.account_id');
        }

        if ($providerId === self::PROVIDER_RUNPOD) {
            $metadata['endpoint_id'] = trim((string) ($metadata['endpoint_id'] ?? ''));
            $metadata['runsync_url'] = trim((string) ($metadata['runsync_url'] ?? ''));
        }

        return $metadata;
    }

    private function providerHasRequiredMetadata(string $providerId, array $metadata): bool
    {
        if ($providerId === self::PROVIDER_RUNPOD) {
            return filled($metadata['runsync_url'] ?? null) || filled($metadata['endpoint_id'] ?? null);
        }

        return true;
    }

    private function runPodEndpointUrl(array $metadata = []): string
    {
        $metadata = $this->metadataWithDefaults(self::PROVIDER_RUNPOD, $metadata);
        $runsyncUrl = trim((string) ($metadata['runsync_url'] ?? ''));

        if ($runsyncUrl !== '') {
            return $runsyncUrl;
        }

        $endpointId = trim((string) ($metadata['endpoint_id'] ?? ''));

        return $endpointId === ''
            ? ''
            : self::RUNPOD_API_BASE_URL.'/'.$endpointId.'/runsync';
    }

    private function nextSortOrder(string $category): int
    {
        $providerIds = $this->providerIdsForCategory($category);

        return ((int) TranscriptionProviderSetting::query()
            ->whereIn('provider', $providerIds)
            ->max('sort_order')) + 1;
    }

    private function providerIdsForCategory(string $category): array
    {
        return array_keys(array_filter(
            $this->providerDefinitions(),
            fn (array $definition): bool => $definition['category'] === $category,
        ));
    }

    private function maskKey(?string $apiKey): string
    {
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return '';
        }

        $apiKey = trim($apiKey);

        return str_repeat('*', max(8, strlen($apiKey) - 4)).substr($apiKey, -4);
    }

    private function defaultLanguageCode(string $provider, string $model): ?string
    {
        return match ($provider) {
            self::PROVIDER_DEEPGRAM => 'multi',
            self::PROVIDER_ELEVENLABS => null,
            self::PROVIDER_GROQ_TRANSCRIPTION => null,
            self::PROVIDER_GLADIA, self::PROVIDER_ASSEMBLYAI, self::PROVIDER_AWS_TRANSCRIBE, self::PROVIDER_RUNPOD => 'auto',
            self::PROVIDER_AZURE_SPEECH, self::PROVIDER_GOOGLE_SPEECH => 'en-US',
            self::PROVIDER_SPEECHMATICS => $model === SpeechmaticsSpeechToTextService::MODEL_MELIA_1 ? 'multi' : 'auto',
            default => null,
        };
    }

    private function languageOptions(string $provider, string $model): array
    {
        return match ($provider) {
            self::PROVIDER_DEEPGRAM => $this->deepgramNova3Languages(),
            self::PROVIDER_ELEVENLABS => $this->elevenLabsScribeLanguages(),
            self::PROVIDER_GROQ_TRANSCRIPTION => [['code' => 'auto', 'label' => 'Automatic detection']],
            self::PROVIDER_GLADIA, self::PROVIDER_AWS_TRANSCRIBE => $this->languageRows([
                'auto' => 'Automatic detection', 'en' => 'English', 'fil' => 'Filipino / Tagalog',
            ]),
            self::PROVIDER_RUNPOD => $this->languageRows([
                'auto' => 'Automatic detection', 'en' => 'English', 'fil' => 'Filipino / Tagalog',
            ]),
            self::PROVIDER_ASSEMBLYAI => $model === AssemblyAiSpeechToTextService::MODEL_UNIVERSAL_3_PRO
                ? $this->languageRows(['auto' => 'Automatic detection', 'en' => 'English', 'es' => 'Spanish', 'de' => 'German', 'fr' => 'French', 'pt' => 'Portuguese', 'it' => 'Italian'])
                : $this->languageRows(['auto' => 'Automatic detection', 'en' => 'English', 'fil' => 'Filipino / Tagalog']),
            self::PROVIDER_AZURE_SPEECH, self::PROVIDER_GOOGLE_SPEECH => $this->languageRows([
                'en-US' => 'English (United States)', 'fil-PH' => 'Filipino (Philippines)',
            ]),
            self::PROVIDER_SPEECHMATICS => $model === SpeechmaticsSpeechToTextService::MODEL_MELIA_1
                ? [['code' => 'multi', 'label' => 'Multilingual']]
                : $this->speechmaticsEnhancedLanguages(),
            default => [],
        };
    }

    private function languageCodeRequired(string $provider, string $model): bool
    {
        return $provider === self::PROVIDER_SPEECHMATICS
            && $model === SpeechmaticsSpeechToTextService::MODEL_MELIA_1;
    }

    private function deepgramNova3Languages(): array
    {
        return $this->languageRows([
            'multi' => 'Multilingual',
            'ar' => 'Arabic',
            'ar-AE' => 'Arabic (United Arab Emirates)',
            'ar-SA' => 'Arabic (Saudi Arabia)',
            'ar-QA' => 'Arabic (Qatar)',
            'ar-KW' => 'Arabic (Kuwait)',
            'ar-SY' => 'Arabic (Syria)',
            'ar-LB' => 'Arabic (Lebanon)',
            'ar-PS' => 'Arabic (Palestine)',
            'ar-JO' => 'Arabic (Jordan)',
            'ar-EG' => 'Arabic (Egypt)',
            'ar-SD' => 'Arabic (Sudan)',
            'ar-TD' => 'Arabic (Chad)',
            'ar-MA' => 'Arabic (Morocco)',
            'ar-DZ' => 'Arabic (Algeria)',
            'ar-TN' => 'Arabic (Tunisia)',
            'ar-IQ' => 'Arabic (Iraq)',
            'ar-IR' => 'Arabic (Iran)',
            'be' => 'Belarusian',
            'bn' => 'Bengali',
            'bs' => 'Bosnian',
            'bg' => 'Bulgarian',
            'ca' => 'Catalan',
            'zh-HK' => 'Chinese (Cantonese, Traditional)',
            'zh' => 'Chinese (Mandarin, Simplified)',
            'zh-CN' => 'Chinese (Mandarin, Simplified China)',
            'zh-Hans' => 'Chinese (Simplified)',
            'zh-TW' => 'Chinese (Mandarin, Traditional Taiwan)',
            'zh-Hant' => 'Chinese (Traditional)',
            'hr' => 'Croatian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'da-DK' => 'Danish (Denmark)',
            'nl' => 'Dutch',
            'en' => 'English',
            'en-US' => 'English (United States)',
            'en-AU' => 'English (Australia)',
            'en-GB' => 'English (United Kingdom)',
            'en-IN' => 'English (India)',
            'en-NZ' => 'English (New Zealand)',
            'et' => 'Estonian',
            'fi' => 'Finnish',
            'nl-BE' => 'Flemish',
            'fr' => 'French',
            'fr-CA' => 'French (Canada)',
            'de' => 'German',
            'de-CH' => 'German (Switzerland)',
            'el' => 'Greek',
            'gu' => 'Gujarati',
            'gu-IN' => 'Gujarati (India)',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'hu' => 'Hungarian',
            'id' => 'Indonesian',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'kn' => 'Kannada',
            'ko' => 'Korean',
            'ko-KR' => 'Korean (South Korea)',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'mk' => 'Macedonian',
            'ms' => 'Malay',
            'mr' => 'Marathi',
            'no' => 'Norwegian',
            'fa' => 'Persian',
            'pl' => 'Polish',
            'pt' => 'Portuguese',
            'pt-BR' => 'Portuguese (Brazil)',
            'pt-PT' => 'Portuguese (Portugal)',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'sr' => 'Serbian',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'es' => 'Spanish',
            'es-419' => 'Spanish (Latin America)',
            'sv' => 'Swedish',
            'sv-SE' => 'Swedish (Sweden)',
            'tl' => 'Tagalog',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'th' => 'Thai',
            'th-TH' => 'Thai (Thailand)',
            'tr' => 'Turkish',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'vi' => 'Vietnamese',
        ]);
    }

    private function elevenLabsScribeLanguages(): array
    {
        return $this->languageRows([
            'auto' => 'Auto detect',
            'en' => 'English',
            'tl' => 'Tagalog / Filipino',
            'ceb' => 'Cebuano / Bisaya',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
            'hi' => 'Hindi',
            'ar' => 'Arabic',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'tr' => 'Turkish',
            'uk' => 'Ukrainian',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'no' => 'Norwegian',
            'cs' => 'Czech',
            'el' => 'Greek',
            'he' => 'Hebrew',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
        ]);
    }

    private function speechmaticsEnhancedLanguages(): array
    {
        return $this->languageRows([
            'auto' => 'Automatic',
            'ar' => 'Arabic',
            'ar_en' => 'Arabic and English',
            'ba' => 'Bashkir',
            'eu' => 'Basque',
            'be' => 'Belarusian',
            'bn' => 'Bengali',
            'bg' => 'Bulgarian',
            'yue' => 'Cantonese',
            'ca' => 'Catalan',
            'hr' => 'Croatian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'nl' => 'Dutch',
            'en' => 'English',
            'eo' => 'Esperanto',
            'et' => 'Estonian',
            'fi' => 'Finnish',
            'fr' => 'French',
            'gl' => 'Galician',
            'de' => 'German',
            'el' => 'Greek',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'hu' => 'Hungarian',
            'id' => 'Indonesian',
            'ia' => 'Interlingua',
            'ga' => 'Irish',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'ms' => 'Malay',
            'en_ms' => 'Malay and English',
            'mt' => 'Maltese',
            'cmn' => 'Mandarin',
            'cmn_en' => 'Mandarin and English',
            'cmn_en_ms_ta' => 'Mandarin, Malay, Tamil and English',
            'mr' => 'Marathi',
            'mn' => 'Mongolian',
            'no' => 'Norwegian',
            'fa' => 'Persian',
            'pl' => 'Polish',
            'pt' => 'Portuguese',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'sk' => 'Slovakian',
            'sl' => 'Slovenian',
            'es' => 'Spanish',
            'sw' => 'Swahili',
            'sv' => 'Swedish',
            'tl' => 'Tagalog (Filipino) and English',
            'ta' => 'Tamil',
            'en_ta' => 'Tamil and English',
            'th' => 'Thai',
            'tr' => 'Turkish',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'ug' => 'Uyghur',
            'vi' => 'Vietnamese',
            'cy' => 'Welsh',
        ]);
    }

    private function languageRows(array $languages): array
    {
        return array_map(
            fn (string $code, string $label): array => [
                'code' => $code,
                'label' => $label,
            ],
            array_keys($languages),
            $languages,
        );
    }
}
