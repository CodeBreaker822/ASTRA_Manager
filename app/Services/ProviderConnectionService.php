<?php

namespace App\Services;

use App\Models\TranscriptionProviderSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ProviderConnectionService
{
    private const RUNPOD_API_BASE_URL = 'https://api.runpod.ai/v2';

    public function __construct(private readonly AppSettingsService $settings) {}

    /**
     * @return array<int, array{status: string, label: string, message: string, checked_at: string}>
     */
    public function checkAll(): array
    {
        $providerCards = collect($this->settings->providerCards())
            ->where('configured', true)
            ->values();
        $settings = TranscriptionProviderSetting::query()
            ->whereIn('id', $providerCards->pluck('setting_id'))
            ->get()
            ->keyBy('id');
        $results = [];
        $requests = [];

        foreach ($providerCards as $provider) {
            $setting = $settings->get($provider['setting_id']);

            if (! $setting) {
                continue;
            }

            if (! $provider['is_enabled']) {
                $results[$provider['setting_id']] = $this->result('disabled', 'Disabled', 'This fallback row is disabled.');

                continue;
            }

            if (in_array($provider['provider'], [AppSettingsService::PROVIDER_GOOGLE_SPEECH, AppSettingsService::PROVIDER_AWS_TRANSCRIBE], true)) {
                $results[$provider['setting_id']] = $this->checkCredentialProvider($provider['provider'], $provider['model'], $setting->api_key);

                continue;
            }

            if ($provider['provider'] === AppSettingsService::PROVIDER_RUNPOD) {
                $results[$provider['setting_id']] = $this->checkRunPodProvider($setting->api_key, $provider['metadata'] ?? []);

                continue;
            }

            $request = $this->requestFor(
                $provider['provider'],
                $provider['model'],
                $setting->api_key,
                $provider['metadata'] ?? [],
            );

            if ($request['url'] === '') {
                $results[$provider['setting_id']] = $this->result('offline', 'Configuration required', 'This provider requires additional server configuration.');

                continue;
            }

            $requests[(string) $provider['setting_id']] = $request;
        }

        if ($requests === []) {
            return $results;
        }

        $responses = Http::pool(function (Pool $pool) use ($requests): void {
            foreach ($requests as $settingId => $request) {
                $pendingRequest = $pool->as($settingId)
                    ->acceptJson()
                    ->connectTimeout((int) config('services.provider_health.connect_timeout', 3))
                    ->timeout((int) config('services.provider_health.timeout', 6));

                if ($request['token'] !== null) {
                    $pendingRequest = $pendingRequest->withToken($request['token'], $request['token_type']);
                }

                if ($request['headers'] !== []) {
                    $pendingRequest = $pendingRequest->withHeaders($request['headers']);
                }

                $pendingRequest->get($request['url'], $request['query']);
            }
        });

        foreach ($requests as $settingId => $request) {
            $results[(int) $settingId] = $this->interpret(
                $responses[$settingId] ?? null,
                $request['provider'],
                $request['model'],
            );
        }

        ksort($results);

        return $results;
    }

    /**
     * @return array{provider: string, model: string, url: string, query: array<string, string>, headers: array<string, string>, token: ?string, token_type: string}
     */
    private function requestFor(string $provider, string $model, string $apiKey, array $metadata = []): array
    {
        $request = [
            'provider' => $provider,
            'model' => $model,
            'query' => [],
            'headers' => [],
            'token' => trim($apiKey),
            'token_type' => 'Bearer',
        ];

        return match ($provider) {
            AppSettingsService::PROVIDER_DEEPGRAM => array_merge($request, [
                'url' => (string) config('services.deepgram.projects_url'),
                'token_type' => 'Token',
            ]),
            AppSettingsService::PROVIDER_ELEVENLABS => array_merge($request, [
                'url' => (string) config('services.elevenlabs.user_url'),
                'headers' => ['xi-api-key' => trim($apiKey)],
                'token' => null,
            ]),
            AppSettingsService::PROVIDER_SPEECHMATICS => $request + [
                'url' => rtrim((string) config('services.speechmatics.base_url'), '/').'/jobs',
            ],
            AppSettingsService::PROVIDER_GLADIA => array_merge($request, [
                'url' => rtrim((string) config('services.gladia.base_url'), '/').'/pre-recorded',
                'headers' => ['x-gladia-key' => trim($apiKey)],
                'token' => null,
            ]),
            AppSettingsService::PROVIDER_ASSEMBLYAI => array_merge($request, [
                'url' => rtrim((string) config('services.assemblyai.base_url'), '/').'/transcript',
                'headers' => ['Authorization' => trim($apiKey)],
                'token' => null,
            ]),
            AppSettingsService::PROVIDER_AZURE_SPEECH => $this->azureHealthRequest($request, $apiKey),
            AppSettingsService::PROVIDER_GEMINI => array_merge($request, [
                'url' => rtrim((string) config('services.gemini.base_url'), '/').'/models/'.rawurlencode($model),
                'query' => ['key' => trim($apiKey)],
                'token' => null,
            ]),
            AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => $request + [
                'url' => rtrim((string) config('services.groq.base_url'), '/').'/models',
            ],
            AppSettingsService::PROVIDER_DEEPSEEK => $request + [
                'url' => (string) config('services.deepseek.models_url'),
            ],
            AppSettingsService::PROVIDER_CEREBRAS => $request + [
                'url' => (string) config('services.cerebras.models_url'),
            ],
            AppSettingsService::PROVIDER_MISTRAL => $request + [
                'url' => (string) config('services.mistral.models_url'),
            ],
            AppSettingsService::PROVIDER_OPENROUTER => $request + [
                'url' => (string) config('services.openrouter.models_url'),
            ],
            AppSettingsService::PROVIDER_CLOUDFLARE => $request + [
                'url' => $this->settings->cloudflareModelsUrl($metadata['account_id'] ?? null),
            ],
            default => $request + ['url' => ''],
        };
    }

    /**
     * @return array{status: string, label: string, message: string, checked_at: string}
     */
    private function interpret(mixed $response, string $provider, string $model): array
    {
        if ($response instanceof ConnectionException || $response instanceof RequestException || ! $response instanceof Response) {
            return $this->result('offline', 'Offline', 'The provider could not be reached.');
        }

        if ($response->status() === 429) {
            return $this->result('limited', 'Rate limited', 'The provider is reachable but is currently rate limited or out of quota.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->result('offline', 'Authentication failed', 'The API key was rejected or cannot access this model.');
        }

        if ($response->status() === 404) {
            return $this->result('offline', 'Model unavailable', 'The configured model is not available.');
        }

        if ($response->failed()) {
            return $this->result('offline', 'Offline', 'The provider returned HTTP '.$response->status().'.');
        }

        if (! $this->responseContainsModel($response, $provider, $model)) {
            return $this->result('offline', 'Model unavailable', 'The provider is reachable, but this model is not available to the API key.');
        }

        return $this->result('online', 'Online', 'The provider and configured model are available.');
    }

    private function responseContainsModel(Response $response, string $provider, string $model): bool
    {
        return match ($provider) {
            AppSettingsService::PROVIDER_DEEPGRAM => is_array($response->json('projects')),
            AppSettingsService::PROVIDER_ELEVENLABS => is_string($response->json('user_id')),
            AppSettingsService::PROVIDER_GEMINI => in_array($response->json('name'), [$model, 'models/'.$model], true)
                && in_array('generateContent', $response->json('supportedGenerationMethods', []), true),
            AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => collect($response->json('data', []))
                ->contains(fn (array $item): bool => ($item['id'] ?? null) === $model && ($item['active'] ?? true)),
            AppSettingsService::PROVIDER_DEEPSEEK => collect($response->json('data', []))
                ->contains(fn (array $item): bool => ($item['id'] ?? null) === $model),
            AppSettingsService::PROVIDER_CEREBRAS,
            AppSettingsService::PROVIDER_MISTRAL,
            AppSettingsService::PROVIDER_OPENROUTER => collect($response->json('data', []))
                ->contains(fn (array $item): bool => ($item['id'] ?? null) === $model),
            AppSettingsService::PROVIDER_CLOUDFLARE => collect($response->json('result', []))
                ->contains(fn (array $item): bool => in_array($model, [$item['name'] ?? null, $item['id'] ?? null], true)),
            AppSettingsService::PROVIDER_SPEECHMATICS => true,
            AppSettingsService::PROVIDER_GLADIA,
            AppSettingsService::PROVIDER_ASSEMBLYAI,
            AppSettingsService::PROVIDER_AZURE_SPEECH => true,
            default => false,
        };
    }

    private function checkRunPodProvider(string $apiKey, array $metadata = []): array
    {
        $endpoint = $this->runPodEndpointUrl($metadata);

        if ($endpoint === '') {
            return $this->result('offline', 'Configuration required', 'RunPod endpoint is not configured.');
        }

        try {
            $response = Http::withToken(trim($apiKey))
                ->acceptJson()
                ->asJson()
                ->connectTimeout((int) config('services.provider_health.connect_timeout', 3))
                ->timeout((int) config('services.provider_health.timeout', 6))
                ->post($endpoint, ['input' => ['action' => 'capabilities']]);
        } catch (ConnectionException|RequestException) {
            return $this->result('offline', 'Offline', 'The provider could not be reached.');
        }

        if ($response->status() === 429) {
            return $this->result('limited', 'Rate limited', 'The provider is reachable but is currently rate limited or out of quota.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->result('offline', 'Authentication failed', 'The API key was rejected or cannot access this endpoint.');
        }

        if ($response->failed()) {
            return $this->result('offline', 'Offline', 'The provider returned HTTP '.$response->status().'.');
        }

        return $this->result('online', 'Online', 'The RunPod endpoint accepted the capabilities request.');
    }

    private function runPodEndpointUrl(array $metadata = []): string
    {
        $runsyncUrl = trim((string) ($metadata['runsync_url'] ?? ''));

        if ($runsyncUrl !== '') {
            return $runsyncUrl;
        }

        $endpointId = trim((string) ($metadata['endpoint_id'] ?? ''));

        return $endpointId === ''
            ? ''
            : self::RUNPOD_API_BASE_URL.'/'.$endpointId.'/runsync';
    }

    private function azureHealthRequest(array $request, string $apiKey): array
    {
        try {
            $credential = (new AzureSpeechToTextService($apiKey))->credential();

            return array_merge($request, [
                'url' => 'https://'.$credential['region'].'.api.cognitive.microsoft.com/speechtotext/locales',
                'query' => ['api-version' => '2025-10-15'],
                'headers' => ['Ocp-Apim-Subscription-Key' => $credential['key']],
                'token' => null,
            ]);
        } catch (\Throwable) {
            return $request + ['url' => ''];
        }
    }

    private function checkCredentialProvider(string $provider, string $model, string $apiKey): array
    {
        try {
            if ($provider === AppSettingsService::PROVIDER_GOOGLE_SPEECH) {
                (new GoogleCloudSpeechToTextService($apiKey, $model))->accessToken();
            } else {
                (new AwsTranscribeSpeechToTextService($apiKey, $model))->checkConnection();
            }

            return $this->result('online', 'Online', 'The provider credentials and required cloud resources are available.');
        } catch (\Throwable $exception) {
            $status = (int) $exception->getCode();

            return $status === 429
                ? $this->result('limited', 'Rate limited', 'The provider is reachable but currently rate limited.')
                : $this->result('offline', 'Authentication failed', 'The provider credentials or required cloud resources were rejected.');
        }
    }

    /**
     * @return array{status: string, label: string, message: string, checked_at: string}
     */
    private function result(string $status, string $label, string $message): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'message' => $message,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
