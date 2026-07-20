<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\API;
use App\Models\TranscriptionApiRequestLog;
use App\Models\TranscriptionProviderSetting;
use App\Services\AppSettingsService;
use App\Services\LicenseKeyService;
use App\Services\ProviderConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class APIController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(AppSettingsService $settings)
    {
        $apis = API::all();

        return view('settings.system_pages.api', [
            'apis' => $apis,
            'transcriptionProviders' => $settings->providerCards(),
            'transcriberPackage' => $this->transcriberPackage(),
        ]);
    }

    public function updateTranscriptionProviders(Request $request, AppSettingsService $settings)
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
        $submittedProviders = collect($validated['providers'])
            ->map(fn (mixed $settingId): int => (int) $settingId)
            ->sort()
            ->values()
            ->all();

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

    public function uploadTranscriberPackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z](?:[0-9A-Za-z._+\-]{0,48}[0-9A-Za-z])?$/'],
            'package' => ['required', 'file', 'mimes:zip', 'max:512000'],
        ], [
            'version.regex' => 'The version may only contain letters, numbers, dots, underscores, plus signs, and hyphens.',
            'package.mimes' => 'The Transcriber App Package must be a ZIP file.',
            'package.max' => 'The Transcriber App Package must not exceed 500 MB.',
        ]);

        $directory = Storage::disk('local')->path('transcriber');
        $version = $validated['version'];
        $filename = 'standalone-transcriber-'.$version.'.zip';
        $temporaryPackage = $directory.DIRECTORY_SEPARATOR.'.upload-'.bin2hex(random_bytes(12)).'.tmp';
        $temporaryVersion = $directory.DIRECTORY_SEPARATOR.'.version-'.bin2hex(random_bytes(12)).'.json';

        try {
            $embeddedVersion = $this->transcriberPackageVersionFromZip((string) $request->file('package')->getRealPath());

            if ($embeddedVersion === null) {
                throw new \RuntimeException('The Transcriber App Package must include a root version.json file.');
            }

            if (! hash_equals($version, $embeddedVersion)) {
                throw new \RuntimeException("The Transcriber App Package version.json version [{$embeddedVersion}] does not match the published version [{$version}].");
            }

            File::ensureDirectoryExists($directory);
            $request->file('package')->move($directory, basename($temporaryPackage));

            File::put($temporaryVersion, json_encode(
                ['version' => $version],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL);

            foreach (File::files($directory) as $file) {
                if (strtolower($file->getExtension()) === 'zip' && $file->getPathname() !== $temporaryPackage) {
                    File::delete($file->getPathname());
                }
            }

            if (! File::move($temporaryPackage, $directory.DIRECTORY_SEPARATOR.$filename)) {
                throw new \RuntimeException('Unable to publish the Transcriber App Package.');
            }

            if (! File::move($temporaryVersion, $directory.DIRECTORY_SEPARATOR.'version.json')) {
                throw new \RuntimeException('Unable to publish the Transcriber App version.');
            }
        } catch (Throwable $exception) {
            File::delete([$temporaryPackage, $temporaryVersion]);
            $errorId = (string) Str::uuid();

            Log::error('Transcriber App Package upload failed.', [
                'error_id' => $errorId,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return response()->json([
                'message' => $this->transcriberPackageUploadError($exception, $errorId),
                'error_id' => $errorId,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transcriber App Package uploaded successfully!',
            'version' => $version,
            'zipfile' => $filename,
        ]);
    }

    public function generateLicenseKey(LicenseKeyService $licenses)
    {
        return response()->json([
            'success' => true,
            'license_key' => $licenses->makeUniqueLicenseKey(),
        ]);
    }

    public function store(Request $request, LicenseKeyService $licenses)
    {
        $validated = $request->validate([
            'app_name' => 'required|string|max:255|unique:a_p_i_s,app_name',
            'app_token' => 'nullable|string|max:255|unique:a_p_i_s,app_token',
            'can_post' => 'sometimes|boolean',
            'can_get' => 'sometimes|boolean',
            'can_put' => 'sometimes|boolean',
            'can_patch' => 'sometimes|boolean',
            'can_delete' => 'sometimes|boolean',
            'blacklisted_ips' => 'nullable|json',
            'blacklisted_routes' => 'nullable|json',
        ]);

        if (blank($validated['app_token'] ?? null)) {
            $validated['app_token'] = $licenses->makeUniqueLicenseKey();
        }

        // Convert checkbox values to integers
        $validated['can_post'] = isset($validated['can_post']) ? 1 : 0;
        $validated['can_get'] = isset($validated['can_get']) ? 1 : 0;
        $validated['can_put'] = isset($validated['can_put']) ? 1 : 0;
        $validated['can_patch'] = isset($validated['can_patch']) ? 1 : 0;
        $validated['can_delete'] = isset($validated['can_delete']) ? 1 : 0;

        $api = API::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'API settings saved successfully!',
            'data' => $api,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $api = API::findOrFail($id);
        $api->is_active = $request->is_active;
        $api->save();

        return response()->json([
            'success' => true,
            'message' => 'API status updated successfully!',
            'data' => $api,
        ]);
    }

    public function updateMethod(Request $request, $id)
    {
        $api = API::findOrFail($id);
        $method = 'can_'.$request->method;
        $api->$method = $request->enabled;
        $api->save();

        return response()->json([
            'success' => true,
            'message' => 'API method updated successfully!',
            'data' => $api,
        ]);
    }

    public function destroy(API $aPI)
    {
        $aPI->delete();

        return response()->json([
            'success' => true,
            'message' => 'API deleted successfully!',
            'data' => $aPI,
        ]);
    }

    private function transcriberPackage(): array
    {
        $directory = Storage::disk('local')->path('transcriber');
        $versionPath = $directory.DIRECTORY_SEPARATOR.'version.json';
        $version = null;

        if (File::isReadable($versionPath)) {
            try {
                $contents = json_decode(File::get($versionPath), true, 512, JSON_THROW_ON_ERROR);
                $version = is_array($contents) ? ($contents['version'] ?? null) : null;
            } catch (Throwable) {
                // Keep the settings page available if a legacy version file is malformed.
            }
        }

        $zipFiles = File::isDirectory($directory)
            ? array_values(array_filter(
                File::files($directory),
                fn ($file): bool => strtolower($file->getExtension()) === 'zip',
            ))
            : [];

        usort($zipFiles, fn ($left, $right): int => strnatcasecmp($left->getFilename(), $right->getFilename()));

        return [
            'version' => $version,
            'zipfile' => $zipFiles === [] ? null : $zipFiles[0]->getFilename(),
        ];
    }

    private function transcriberPackageUploadError(Throwable $exception, string $errorId): string
    {
        $detail = trim($exception->getMessage());

        if ($detail === '') {
            return "Transcriber package upload failed. Error reference: {$errorId}.";
        }

        $detail = str_replace(
            array_filter([base_path(), storage_path(), sys_get_temp_dir()]),
            ['[application]', '[storage]', '[temporary directory]'],
            $detail,
        );
        $detail = Str::limit(preg_replace('/\s+/', ' ', $detail) ?? $detail, 350, '...');

        return "Transcriber package upload failed: {$detail} Error reference: {$errorId}.";
    }

    private function transcriberPackageVersionFromZip(string $path): ?string
    {
        $versionJson = $this->readFileFromZip($path, 'version.json');

        if ($versionJson === null) {
            return null;
        }

        $payload = json_decode($versionJson, true);

        return is_array($payload) && is_string($payload['version'] ?? null)
            ? trim($payload['version'])
            : null;
    }

    private function readFileFromZip(string $path, string $wantedName): ?string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to read the uploaded Transcriber App Package.');
        }

        try {
            $size = filesize($path);

            if ($size === false || $size < 22) {
                throw new \RuntimeException('The Transcriber App Package is not a readable ZIP file.');
            }

            $tailSize = min($size, 65557);
            fseek($handle, -$tailSize, SEEK_END);
            $tail = fread($handle, $tailSize);
            $endOffset = is_string($tail) ? strrpos($tail, "PK\x05\x06") : false;

            if ($endOffset === false) {
                throw new \RuntimeException('The Transcriber App Package is not a readable ZIP file.');
            }

            $endRecord = substr($tail, $endOffset, 22);
            $directory = unpack('ventries/vtotal/Vsize/Voffset', substr($endRecord, 8, 12));

            if (! is_array($directory)) {
                throw new \RuntimeException('The Transcriber App Package directory could not be read.');
            }

            fseek($handle, (int) $directory['offset']);
            $read = 0;

            while ($read < (int) $directory['size']) {
                $header = fread($handle, 46);

                if (! is_string($header) || strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") {
                    throw new \RuntimeException('The Transcriber App Package directory is malformed.');
                }

                $entry = unpack(
                    'x10/vmethod/x8/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/x8/VlocalOffset',
                    $header,
                );

                if (! is_array($entry)) {
                    throw new \RuntimeException('The Transcriber App Package directory entry could not be read.');
                }

                $name = fread($handle, (int) $entry['nameLength']);
                $extra = (int) $entry['extraLength'];
                $comment = (int) $entry['commentLength'];

                if ($extra > 0) {
                    fseek($handle, $extra, SEEK_CUR);
                }

                if ($comment > 0) {
                    fseek($handle, $comment, SEEK_CUR);
                }

                $read += 46 + (int) $entry['nameLength'] + $extra + $comment;

                if (! is_string($name) || ltrim(str_replace('\\', '/', $name), './') !== $wantedName) {
                    continue;
                }

                return $this->readZipEntryContents($handle, (int) $entry['localOffset'], (int) $entry['method'], (int) $entry['compressedSize']);
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function readZipEntryContents(mixed $handle, int $localOffset, int $method, int $compressedSize): string
    {
        if ($compressedSize > 1024 * 1024) {
            throw new \RuntimeException('The Transcriber App Package version.json file is too large.');
        }

        fseek($handle, $localOffset);
        $localHeader = fread($handle, 30);

        if (! is_string($localHeader) || strlen($localHeader) !== 30 || substr($localHeader, 0, 4) !== "PK\x03\x04") {
            throw new \RuntimeException('The Transcriber App Package file entry is malformed.');
        }

        $local = unpack('vnameLength/vextraLength', substr($localHeader, 26, 4));

        if (! is_array($local)) {
            throw new \RuntimeException('The Transcriber App Package file entry could not be read.');
        }

        fseek($handle, (int) $local['nameLength'] + (int) $local['extraLength'], SEEK_CUR);
        $contents = $compressedSize === 0 ? '' : fread($handle, $compressedSize);

        if (! is_string($contents) || strlen($contents) !== $compressedSize) {
            throw new \RuntimeException('The Transcriber App Package version.json file could not be read.');
        }

        return match ($method) {
            0 => $contents,
            8 => gzinflate($contents) ?: throw new \RuntimeException('The Transcriber App Package version.json file could not be decompressed.'),
            default => throw new \RuntimeException('The Transcriber App Package version.json file uses an unsupported ZIP compression method.'),
        };
    }
}
