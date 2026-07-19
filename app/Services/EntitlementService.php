<?php

namespace App\Services;

use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

class EntitlementService
{
    /**
     * @return array<string, mixed>
     */
    public function planFor(User $user): array
    {
        $plans = config('plans.tiers', []);
        $plans = is_array($plans) ? $plans : [];
        $default = (string) config('plans.default', 'free');
        $planKey = array_key_exists((string) $user->plan, $plans)
            ? (string) $user->plan
            : $default;
        $plan = $plans[$planKey] ?? [];

        return array_merge(['key' => $planKey], is_array($plan) ? $plan : []);
    }

    public function allows(User $user, string $feature): bool
    {
        return (bool) data_get($this->planFor($user), "entitlements.{$feature}", false);
    }

    public function allowsExport(User $user, string $format): bool
    {
        $exports = data_get($this->planFor($user), 'entitlements.exports', []);

        return is_array($exports) && in_array($format, $exports, true);
    }

    public function usageForCurrentPeriod(User $user): UsageRecord
    {
        return UsageRecord::query()->firstOrCreate([
            'user_id' => $user->id,
            'period' => Carbon::now()->format('Y-m'),
        ]);
    }

    /**
     * @return array{plan: array{key: string, name: string, minutes: int, features: mixed}, usage: array{period: string, seconds_transcribed: int, minutes_used: int, minutes_remaining: int, polish_count: int, summary_count: int}}
     */
    public function summaryFor(User $user): array
    {
        $plan = $this->planFor($user);
        $usage = $this->usageForCurrentPeriod($user);
        $includedMinutes = (int) ($plan['minutes'] ?? 0);
        $usedSeconds = (int) $usage->seconds_transcribed;
        $usedMinutes = (int) ceil($usedSeconds / 60);

        return [
            'plan' => [
                'key' => $plan['key'],
                'name' => (string) ($plan['name'] ?? 'Free'),
                'minutes' => $includedMinutes,
                'features' => $plan['entitlements'] ?? [],
            ],
            'usage' => [
                'period' => $usage->period,
                'seconds_transcribed' => $usedSeconds,
                'minutes_used' => $usedMinutes,
                'minutes_remaining' => max(0, $includedMinutes - $usedMinutes),
                'polish_count' => (int) $usage->polish_count,
                'summary_count' => (int) $usage->summary_count,
            ],
        ];
    }

    public function canTranscribe(User $user, int $additionalSeconds = 0): bool
    {
        $plan = $this->planFor($user);
        $quotaSeconds = (int) ($plan['minutes'] ?? 0) * 60;
        $usage = $this->usageForCurrentPeriod($user);

        return (int) $usage->seconds_transcribed + $additionalSeconds <= $quotaSeconds;
    }
}
