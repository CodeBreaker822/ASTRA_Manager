<?php

use App\Models\TranscriptionProviderSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        TranscriptionProviderSetting::query()
            ->select(['id', 'api_key'])
            ->whereNotNull('api_key')
            ->orderBy('id')
            ->each(function (TranscriptionProviderSetting $setting): void {
                $rawApiKey = (string) $setting->getRawOriginal('api_key');

                if ($rawApiKey === '' || $this->looksLikeLaravelEncryptedPayload($rawApiKey)) {
                    return;
                }

                $setting->forceFill([
                    'api_key' => $rawApiKey,
                ])->saveQuietly();
            });
    }

    public function down(): void
    {
        //
    }

    private function looksLikeLaravelEncryptedPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if (! is_string($decoded)) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && is_string($payload['iv'] ?? null)
            && is_string($payload['value'] ?? null)
            && (is_string($payload['mac'] ?? null) || is_string($payload['tag'] ?? null));
    }
};
