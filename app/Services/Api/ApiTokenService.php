<?php

namespace App\Services\Api;

use App\Models\API;
use App\Services\LicenseKeyService;
use Illuminate\Validation\ValidationException;

class ApiTokenService
{
    public function __construct(private readonly LicenseKeyService $licenses) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForManager(): array
    {
        return API::query()
            ->whereNull('user_id')
            ->latest()
            ->get()
            ->map(fn (API $api): array => $this->present($api))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{api: array<string, mixed>, plain_token: string}
     */
    public function create(array $data): array
    {
        $plainToken = filled($data['app_token'] ?? null)
            ? (string) $data['app_token']
            : $this->licenses->makeUniqueLicenseKey();

        if (API::query()->where('app_token_hash', API::hashToken($plainToken))->exists()) {
            throw ValidationException::withMessages([
                'app_token' => 'This license key has already been issued.',
            ]);
        }

        $api = API::query()->create([
            'app_name' => $data['app_name'],
            'app_token' => $plainToken,
            'can_post' => (bool) ($data['can_post'] ?? false),
            'can_get' => (bool) ($data['can_get'] ?? false),
            'can_put' => (bool) ($data['can_put'] ?? false),
            'can_patch' => (bool) ($data['can_patch'] ?? false),
            'can_delete' => (bool) ($data['can_delete'] ?? false),
            'blacklisted_ips' => $data['blacklisted_ips'] ?? null,
            'blacklisted_routes' => $data['blacklisted_routes'] ?? null,
        ]);

        return [
            'api' => $this->present($api),
            'plain_token' => $plainToken,
        ];
    }

    public function updateStatus(API $api, bool $active): API
    {
        $this->ensureGlobalToken($api);

        $api->forceFill(['is_active' => $active])->save();

        return $api->refresh();
    }

    public function updateMethod(API $api, string $method, bool $enabled): API
    {
        $this->ensureGlobalToken($api);

        $api->forceFill(['can_'.$method => $enabled])->save();

        return $api->refresh();
    }

    public function delete(API $api): void
    {
        $this->ensureGlobalToken($api);

        $api->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(API $api): array
    {
        return [
            'id' => $api->id,
            'app_name' => $api->app_name,
            'token_suffix' => $api->app_token_suffix ?: substr((string) $api->app_token, -12),
            'masked_token' => str_repeat('*', 20).($api->app_token_suffix ?? ''),
            'can_post' => (bool) $api->can_post,
            'can_get' => (bool) $api->can_get,
            'can_put' => (bool) $api->can_put,
            'can_patch' => (bool) $api->can_patch,
            'can_delete' => (bool) $api->can_delete,
            'blacklisted_ips' => $api->blacklisted_ips ?? [],
            'blacklisted_routes' => $api->blacklisted_routes ?? [],
            'is_active' => (bool) $api->is_active,
        ];
    }

    private function ensureGlobalToken(API $api): void
    {
        abort_if($api->user_id !== null, 404);
    }
}
