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
use App\Services\LicenseKeyService;
use App\Services\ProviderConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        ]);

        $providerCatalog = collect($settings->providerCards());

        foreach ($validated['providers'] as $provider => $data) {
            $settingId = isset($data['setting_id']) ? (int) $data['setting_id'] : null;
            $existingProvider = $settingId
                ? $providerCatalog->first(fn (array $item): bool => $item['setting_id'] === $settingId && $item['provider'] === $provider)
                : $providerCatalog->first(fn (array $item): bool => ! $item['configured']
                    && $item['provider'] === $provider
                    && in_array($data['model'], $item['models'], true));

            if (! $existingProvider || ! in_array($data['model'], $existingProvider['models'], true)) {
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
                && blank($data['account_id'] ?? $existingProvider['metadata']['account_id'] ?? config('services.cloudflare.account_id'))) {
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

    public function transcriptionProviderHealth(ProviderConnectionService $connections): JsonResponse
    {
        return response()->json([
            'providers' => $connections->checkAll(),
        ]);
    }

    public function transcriptionProviderLogs(Request $request, AppSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'in:transcriber,text_fixer'],
        ]);

        $operations = $validated['category'] === 'transcriber'
            ? ['transcribe_provider']
            : ['polish_provider', 'chatbot_provider'];

        $providerNames = collect($settings->providerCards())
            ->groupBy('provider')
            ->map(fn ($providers): string => (string) $providers->first()['name']);

        $logs = TranscriptionApiRequestLog::query()
            ->whereIn('operation', $operations)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (TranscriptionApiRequestLog $log): array => [
                'id' => $log->id,
                'created_at' => $log->created_at?->toISOString(),
                'source' => match ($log->operation) {
                    'transcribe_provider' => 'Transcription',
                    'polish_provider' => 'Text polishing',
                    'chatbot_provider' => 'Chatbot',
                    default => $log->operation,
                },
                'provider' => $providerNames->get($log->provider, Str::headline((string) $log->provider)),
                'model' => $log->model,
                'status' => $log->status,
                'http_status' => $log->http_status,
                'fallback_position' => data_get($log->request_summary, 'fallback_position'),
                'error' => $log->error_message,
            ]);

        return response()->json(['logs' => $logs]);
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
}
