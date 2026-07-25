<?php

namespace App\Services;

use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Billing\Money;
use Illuminate\Support\Carbon;

class EntitlementService
{
    public function __construct(private readonly PlanService $plans) {}

    public function allows(User $user, string $feature): bool
    {
        return in_array($feature, ['upload', 'live', 'polish', 'summarize'], true);
    }

    public function allowsExport(User $user, string $format): bool
    {
        return in_array($format, ['txt', 'docx', 'xlsx'], true);
    }

    public function usageForCurrentPeriod(User $user): UsageRecord
    {
        UsageRecord::query()->insertOrIgnore([
            'user_id' => $user->id,
            'period' => Carbon::now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('period', Carbon::now()->toDateString())
            ->firstOrFail();
    }

    public function summaryFor(User $user): array
    {
        $usage = $this->usageForCurrentPeriod($user);
        $free = $this->plans->plan('free') ?? [];

        $freeSecondsLimit = max(0, (int) ($free['minutes'] ?? 0) * 60);
        $usedFreeSeconds = min($freeSecondsLimit, (int) $usage->seconds_transcribed);

        return [
            'wallet' => [
                'balance_nanos' => (int) $user->wallet_balance_nanos,
                'reserved_nanos' => (int) $user->wallet_reserved_nanos,
                'available_nanos' => max(
                    0,
                    (int) $user->wallet_balance_nanos - (int) $user->wallet_reserved_nanos,
                ),
                'balance_formatted' => Money::formatNanos((int) $user->wallet_balance_nanos),
                'available_formatted' => Money::formatNanos(max(
                    0,
                    (int) $user->wallet_balance_nanos - (int) $user->wallet_reserved_nanos,
                )),
            ],
            'usage' => [
                'period' => $usage->period,
                'seconds_transcribed' => (int) $usage->seconds_transcribed,
                'free_seconds_remaining' => max(
                    0,
                    $freeSecondsLimit
                    - $usedFreeSeconds
                    - (int) $usage->free_seconds_reserved,
                ),
                'polish_count' => (int) $usage->polish_count,
                'summary_count' => (int) $usage->summary_count,
                'free_polish_remaining' => max(
                    0,
                    (int) ($free['free_polish_uses_per_day'] ?? 0)
                    - min(
                        (int) ($free['free_polish_uses_per_day'] ?? 0),
                        (int) $usage->polish_count,
                    )
                    - (int) $usage->free_polish_reserved,
                ),
                'free_summary_remaining' => max(
                    0,
                    (int) ($free['free_summary_uses_per_day'] ?? 0)
                    - min(
                        (int) ($free['free_summary_uses_per_day'] ?? 0),
                        (int) $usage->summary_count,
                    )
                    - (int) $usage->free_summary_reserved,
                ),
            ],
        ];
    }
}