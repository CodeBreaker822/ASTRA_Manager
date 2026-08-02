<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiTokenRequest;
use App\Http\Requests\Api\UpdateApiMethodRequest;
use App\Http\Requests\Api\UpdateApiStatusRequest;
use App\Models\API;
use App\Models\TranscriptionApiRequestLog;
use App\Models\TranscriptionProviderSetting;
use App\Services\Api\ApiTokenService;
use App\Services\Api\TranscriberPackageService;
use App\Services\AppSettingsService;
use App\Services\ChunkedUploadService;
use App\Services\LicenseKeyService;
use App\Services\ProviderConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class APIController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(
        AppSettingsService $settings,
        ApiTokenService $tokens,
        TranscriberPackageService $packages,
    ): Response {
        return Inertia::render('dashboard/Api', [
            'apis' => $tokens->listForManager(),
            'transcriptionProviders' => array_values($settings->providerCards()),
            'transcriberPackage' => $packages->current(),
        ]);
    }

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
            ->map(fn (TranscriptionApiRequestLog $log): array => [
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
            ]);

        return response()->json([
            'logs' => $logs->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
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

    public function uploadTranscriberPackage(Request $request, TranscriberPackageService $packages): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z](?:[0-9A-Za-z._+\-]{0,48}[0-9A-Za-z])?$/'],
            'package' => ['required', 'file', 'mimes:zip', 'max:512000'],
        ], [
            'version.regex' => 'The version may only contain letters, numbers, dots, underscores, plus signs, and hyphens.',
            'package.mimes' => 'The Transcriber App Package must be a ZIP file.',
            'package.max' => 'The Transcriber App Package must not exceed 500 MB.',
        ]);

        $version = $validated['version'];

        try {
            $published = $packages->publish($version, $request->file('package'));
        } catch (Throwable $exception) {
            $errorId = (string) Str::uuid();

            Log::error('Transcriber App Package upload failed.', [
                'error_id' => $errorId,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return response()->json([
                'message' => $packages->uploadError($exception, $errorId),
                'error_id' => $errorId,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transcriber App Package uploaded successfully!',
            'version' => $published['version'],
            'zipfile' => $published['zipfile'],
        ]);
    }

    public function uploadTranscriberPackageChunk(Request $request, ChunkedUploadService $chunks): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'min:8', 'max:80'],
            'chunk' => ['required', 'file', 'max:51200'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:200'],
            'total_size' => ['required', 'integer', 'min:1', 'max:'.ChunkedUploadService::MAX_TOTAL_BYTES],
            'filename' => ['required', 'string', 'max:180'],
            'mime_type' => ['nullable', 'string', 'max:120'],
            'chunk_hash' => ['nullable', 'string', 'size:64'],
        ]);

        try {
            $payload = $chunks->storeChunk(
                'transcriber-package',
                $this->packageUploadOwnerKey($request),
                (string) $validated['upload_id'],
                $request->file('chunk'),
                (int) $validated['chunk_index'],
                (int) $validated['total_chunks'],
                (int) $validated['total_size'],
                (string) $validated['filename'],
                isset($validated['mime_type']) ? (string) $validated['mime_type'] : null,
                isset($validated['chunk_hash']) ? (string) $validated['chunk_hash'] : null,
            );
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function completeTranscriberPackageUpload(
        Request $request,
        ChunkedUploadService $chunks,
        TranscriberPackageService $packages,
    ): JsonResponse {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'min:8', 'max:80'],
            'version' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._+-]+$/'],
        ], [
            'version.regex' => 'The version may only contain letters, numbers, dots, underscores, plus signs, and hyphens.',
        ]);

        $uploadId = (string) $validated['upload_id'];
        $ownerKey = $this->packageUploadOwnerKey($request);

        try {
            $assembled = $chunks->assemble('transcriber-package', $ownerKey, $uploadId);

            if (! str_ends_with(strtolower($assembled['filename']), '.zip')) {
                throw new \RuntimeException('The Transcriber App Package must be a ZIP file.');
            }

            $published = $packages->publish(
                (string) $validated['version'],
                new UploadedFile(
                    $assembled['path'],
                    $assembled['filename'],
                    $assembled['mime_type'],
                    null,
                    true,
                ),
            );
        } catch (Throwable $exception) {
            $errorId = (string) Str::uuid();

            Log::error('Chunked Transcriber App Package upload failed.', [
                'error_id' => $errorId,
                'user_id' => $request->user()?->id,
                'upload_id' => $uploadId,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return response()->json([
                'message' => $packages->uploadError($exception, $errorId),
                'error_id' => $errorId,
            ], 500);
        } finally {
            $chunks->cleanup('transcriber-package', $ownerKey, $uploadId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transcriber App Package uploaded successfully!',
            'version' => $published['version'],
            'zipfile' => $published['zipfile'],
        ]);
    }

    public function generateLicenseKey(LicenseKeyService $licenses): JsonResponse
    {
        return response()->json([
            'success' => true,
            'license_key' => $licenses->makeUniqueLicenseKey(),
        ]);
    }

    public function store(StoreApiTokenRequest $request, ApiTokenService $tokens): JsonResponse
    {
        $created = $tokens->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'API settings saved successfully!',
            'data' => $created['api'],
            'plain_token' => $created['plain_token'],
        ]);
    }

    public function updateStatus(UpdateApiStatusRequest $request, API $api, ApiTokenService $tokens): JsonResponse
    {
        $api = $tokens->updateStatus($api, (bool) $request->validated('is_active'));

        return response()->json([
            'success' => true,
            'message' => 'API status updated successfully!',
            'data' => $tokens->present($api),
        ]);
    }

    public function updateMethod(UpdateApiMethodRequest $request, API $api, ApiTokenService $tokens): JsonResponse
    {
        $validated = $request->validated();
        $api = $tokens->updateMethod($api, (string) $validated['method'], (bool) $validated['enabled']);

        return response()->json([
            'success' => true,
            'message' => 'API method updated successfully!',
            'data' => $tokens->present($api),
        ]);
    }

    public function destroy(API $api, ApiTokenService $tokens): JsonResponse
    {
        $tokens->delete($api);

        return response()->json([
            'success' => true,
            'message' => 'API deleted successfully!',
        ]);
    }

    private function packageUploadOwnerKey(Request $request): string
    {
        return 'user-'.$request->user()?->id.'-transcriber-package';
    }

    private function supportsCredentialModelDiscovery(string $provider): bool
    {
        return in_array($provider, [
            AppSettingsService::PROVIDER_GEMINI,
            AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION,
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER,
            AppSettingsService::PROVIDER_MISTRAL,
            AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION,
        ], true);
    }
}
