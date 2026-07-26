<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    public function __construct(private readonly PlanService $plans) {}

    /**
     * @return array<string, mixed>
     */
    public function planFor(User $user): array
    {
        $plans = $this->plans->tiers();
        $default = $this->plans->defaultKey();
        $planKey = array_key_exists((string) $user->plan, $plans)
            ? (string) $user->plan
            : $default;
        $plan = $plans[$planKey] ?? [];

        return array_merge(['key' => $planKey], $plan);
    }

    public function allows(User $user, string $feature): bool
    {
        return in_array($feature, ['upload', 'live', 'polish', 'summarize'], true)
            || (bool) data_get($this->planFor($user), "entitlements.{$feature}", false);
    }

    public function allowsExport(User $user, string $format): bool
    {
        return in_array($format, ['txt', 'docx', 'xlsx'], true);
    }

    public function usageForCurrentPeriod(User $user): UsageRecord
    {
        return UsageRecord::query()->firstOrCreate([
            'user_id' => $user->id,
            'period' => Carbon::now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryFor(User $user): array
    {
        $usage = $this->usageForCurrentPeriod($user);
        $includedMinutes = $this->dailyFreeMinutes();
        $freePolishUses = $this->dailyFreePolishUses();
        $freeSummaryUses = $this->dailyFreeSummaryUses();
        $usedSeconds = (int) $usage->seconds_transcribed;
        $usedMinutes = (int) ceil($usedSeconds / 60);

        return [
            'plan' => [
                'key' => 'payg',
                'name' => 'Pay as you go',
                'minutes' => $includedMinutes,
                'free_polish_uses_per_day' => $freePolishUses,
                'free_summary_uses_per_day' => $freeSummaryUses,
                'features' => [
                    'upload' => true,
                    'live' => true,
                    'polish' => true,
                    'summarize' => true,
                    'exports' => ['txt', 'docx', 'xlsx'],
                ],
            ],
            'usage' => [
                'period' => $usage->period,
                'seconds_transcribed' => $usedSeconds,
                'minutes_used' => $usedMinutes,
                'minutes_remaining' => max(0, $includedMinutes - $usedMinutes),
                'polish_count' => (int) $usage->polish_count,
                'summary_count' => (int) $usage->summary_count,
                'free_polish_remaining' => max(0, $freePolishUses - (int) $usage->polish_count),
                'free_summary_remaining' => max(0, $freeSummaryUses - (int) $usage->summary_count),
                'wallet_balance' => (float) $user->wallet_balance,
                'wallet_balance_cents' => $this->dollarsToCents((float) $user->wallet_balance),
            ],
        ];
    }

    public function canAfford(User $user, string $feature, float $units): bool
    {
        return (float) $user->wallet_balance >= $this->paidCost($user, $feature, $units);
    }

    public function charge(User $user, string $feature, float $units): void
    {
        $units = max(0, $units);

        if ($units <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $feature, $units): void {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $usage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $usage) {
                $usage = UsageRecord::query()->create([
                    'user_id' => $lockedUser->id,
                    'period' => Carbon::now()->toDateString(),
                ]);
            }

            $cost = $this->paidCostForUsage($usage, $feature, $units);

            if ((float) $lockedUser->wallet_balance < $cost) {
                throw new InsufficientWalletBalanceException;
            }

            $this->recordUsage($usage, $feature, $units);

            if ($cost > 0) {
                $lockedUser->forceFill([
                    'wallet_balance' => round((float) $lockedUser->wallet_balance - $cost, 2),
                ])->save();
            }
        });
    }

    private function paidCost(User $user, string $feature, float $units): float
    {
        return $this->paidCostForUsage(
            $this->usageForCurrentPeriod($user),
            $feature,
            max(0, $units),
        );
    }

    private function paidCostForUsage(UsageRecord $usage, string $feature, float $units): float
    {
        $rate = $this->ratePerUnit($feature);

        if ($rate === null || $rate <= 0) {
            return 0.0;
        }

        $paidUnits = match ($feature) {
            'upload', 'live' => max(0, $units - $this->freeSecondsRemaining($usage)),
            'polish' => (int) $usage->polish_count < $this->dailyFreePolishUses() ? 0 : $units,
            'summarize' => (int) $usage->summary_count < $this->dailyFreeSummaryUses() ? 0 : $units,
            default => throw new \InvalidArgumentException("Unknown billing feature: {$feature}"),
        };

        $cost = match ($feature) {
            'upload', 'live' => ($paidUnits / 3600) * $rate,
            'polish', 'summarize' => $paidUnits * $rate,
            default => 0,
        };

        return round($cost, 2);
    }

    private function recordUsage(UsageRecord $usage, string $feature, float $units): void
    {
        match ($feature) {
            'upload', 'live' => $usage->increment('seconds_transcribed', (int) ceil($units)),
            'polish' => $usage->increment('polish_count'),
            'summarize' => $usage->increment('summary_count'),
            default => throw new \InvalidArgumentException("Unknown billing feature: {$feature}"),
        };
    }

    private function freeSecondsRemaining(UsageRecord $usage): int
    {
        return max(0, ($this->dailyFreeMinutes() * 60) - (int) $usage->seconds_transcribed);
    }

    private function dailyFreeMinutes(): int
    {
        $fallback = config('plans.tiers.free', []);

        return max(0, (int) data_get($this->plans->plan('free') ?? $fallback, 'minutes', 0));
    }

    private function dailyFreePolishUses(): int
    {
        $fallback = config('plans.tiers.free', []);

        return max(0, (int) data_get($this->plans->plan('free') ?? $fallback, 'free_polish_uses_per_day', 0));
    }

    private function dailyFreeSummaryUses(): int
    {
        $fallback = config('plans.tiers.free', []);

        return max(0, (int) data_get($this->plans->plan('free') ?? $fallback, 'free_summary_uses_per_day', 0));
    }

    private function ratePerUnit(string $feature): ?float
    {
        return $this->plans->ratePerUnit($feature);
    }

    private function dollarsToCents(float $value): int
    {
        return (int) round($value * 100);
    }
}
