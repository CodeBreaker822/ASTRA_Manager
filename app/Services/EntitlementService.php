<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\UsageRecord;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EntitlementService
{
    public function __construct(private readonly PlanService $plans) {}

    /**
     * @return array<string, mixed>
     */
    public function planFor(User $user): array
    {
        $plans = $this->plans->tiers();
        $planKey = (string) $user->plan;
        $plan = $plans[$planKey] ?? null;

        if (! is_array($plan)) {
            throw new RuntimeException("Pricing plan [{$planKey}] is not configured. Update Pricing Manager before billing users.");
        }

        return array_merge(['key' => $planKey], $plan);
    }

    public function allows(User $user, string $feature): bool
    {
        return $this->entitlementEnabled($this->planFor($user), $feature);
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
        $currentPlan = $this->planFor($user);
        $freePlan = $this->requiredPlan('free');
        $paygPlan = $this->requiredPlan('payg');
        $includedMinutes = $this->dailyFreeMinutesFrom($freePlan);
        $freePolishUses = $this->dailyFreePolishUsesFrom($freePlan);
        $freeSummaryUses = $this->dailyFreeSummaryUsesFrom($freePlan);
        $usedSeconds = (int) $usage->seconds_transcribed;
        $usedMinutes = (int) ceil($usedSeconds / 60);
        $walletBalanceCents = Money::decimalDollarsToUsdCents($user->wallet_balance);

        return [
            'plan' => [
                'key' => (string) $currentPlan['key'],
                'name' => $this->requiredString($currentPlan, 'name', (string) $currentPlan['key']),
                'minutes' => $includedMinutes,
                'free_polish_uses_per_day' => $freePolishUses,
                'free_summary_uses_per_day' => $freeSummaryUses,
                'features' => [
                    'upload' => $this->entitlementEnabled($currentPlan, 'upload'),
                    'live' => $this->entitlementEnabled($currentPlan, 'live'),
                    'polish' => $this->entitlementEnabled($currentPlan, 'polish'),
                    'summarize' => $this->entitlementEnabled($currentPlan, 'summarize'),
                    'exports' => $this->exportEntitlements($currentPlan),
                ],
            ],
            'free_tier' => [
                'key' => 'free',
                'name' => $this->requiredString($freePlan, 'name', 'free'),
                'minutes' => $includedMinutes,
                'free_polish_uses_per_day' => $freePolishUses,
                'free_summary_uses_per_day' => $freeSummaryUses,
            ],
            'pay_as_you_go' => [
                'key' => 'payg',
                'name' => $this->requiredString($paygPlan, 'name', 'payg'),
                'rates' => [
                    'upload_price_per_hour' => $this->ratePerUnit('upload'),
                    'live_price_per_hour' => $this->ratePerUnit('live'),
                    'polish_price_per_character' => $this->ratePerUnit('polish'),
                    'summary_price_per_character' => $this->ratePerUnit('summarize'),
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
                'wallet_balance' => Money::usdCentsToDollars($walletBalanceCents),
                'wallet_balance_cents' => $walletBalanceCents,
            ],
        ];
    }

    public function canAfford(User $user, string $feature, float $units): bool
    {
        return Money::decimalDollarsToUsdCents($user->wallet_balance) >= $this->paidCostCents($user, $feature, $units);
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

            $costCents = $this->paidCostCentsForUsage($usage, $feature, $units);
            $balanceCents = Money::decimalDollarsToUsdCents($lockedUser->wallet_balance);

            if ($balanceCents < $costCents) {
                throw new InsufficientWalletBalanceException;
            }

            $this->recordUsage($usage, $feature, $units, $costCents);

            if ($costCents > 0) {
                $lockedUser->forceFill([
                    'wallet_balance' => Money::usdCentsToDecimalDollars($balanceCents - $costCents),
                ])->save();
            }
        });
    }

    private function paidCostCents(User $user, string $feature, float $units): int
    {
        return $this->paidCostCentsForUsage(
            $this->usageForCurrentPeriod($user),
            $feature,
            max(0, $units),
        );
    }

    private function paidCostCentsForUsage(UsageRecord $usage, string $feature, float $units): int
    {
        $rate = $this->ratePerUnit($feature);

        $paidUnits = match ($feature) {
            'upload', 'live' => max(0, $units - $this->freeSecondsRemaining($usage)),
            'polish' => (int) $usage->polish_count < $this->dailyFreePolishUses() ? 0 : $units,
            'summarize' => (int) $usage->summary_count < $this->dailyFreeSummaryUses() ? 0 : $units,
            default => throw new \InvalidArgumentException("Unknown billing feature: {$feature}"),
        };

        if ($paidUnits <= 0) {
            return 0;
        }

        if ($rate <= 0) {
            throw new RuntimeException("Pay-as-you-go rate for [{$feature}] must be greater than zero before billing paid usage.");
        }

        $cost = in_array($feature, ['upload', 'live'], true)
            ? ($paidUnits / 3600) * $rate
            : $paidUnits * $rate;

        return max(1, (int) ceil($cost * 100));
    }

    private function recordUsage(UsageRecord $usage, string $feature, float $units, int $costCents): void
    {
        $increments = match ($feature) {
            'upload' => [
                'seconds_transcribed' => (int) ceil($units),
                'charged_cents' => $costCents,
                'upload_charged_cents' => $costCents,
            ],
            'live' => [
                'seconds_transcribed' => (int) ceil($units),
                'charged_cents' => $costCents,
                'live_charged_cents' => $costCents,
            ],
            'polish' => [
                'polish_count' => 1,
                'charged_cents' => $costCents,
                'polish_charged_cents' => $costCents,
            ],
            'summarize' => [
                'summary_count' => 1,
                'charged_cents' => $costCents,
                'summary_charged_cents' => $costCents,
            ],
            default => throw new \InvalidArgumentException("Unknown billing feature: {$feature}"),
        };

        $usage->incrementEach($increments);
    }

    private function freeSecondsRemaining(UsageRecord $usage): int
    {
        return max(0, ($this->dailyFreeMinutes() * 60) - (int) $usage->seconds_transcribed);
    }

    private function dailyFreeMinutes(): int
    {
        return $this->dailyFreeMinutesFrom($this->requiredPlan('free'));
    }

    private function dailyFreePolishUses(): int
    {
        return $this->dailyFreePolishUsesFrom($this->requiredPlan('free'));
    }

    private function dailyFreeSummaryUses(): int
    {
        return $this->dailyFreeSummaryUsesFrom($this->requiredPlan('free'));
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredPlan(string $key): array
    {
        $plan = $this->plans->plan($key);

        if (! is_array($plan)) {
            throw new RuntimeException("Pricing plan [{$key}] is not configured. Update Pricing Manager before billing users.");
        }

        return $plan;
    }

    private function dailyFreeMinutesFrom(array $plan): int
    {
        return max(0, $this->requiredInteger($plan, 'minutes', 'free'));
    }

    private function dailyFreePolishUsesFrom(array $plan): int
    {
        return max(0, $this->requiredInteger($plan, 'free_polish_uses_per_day', 'free'));
    }

    private function dailyFreeSummaryUsesFrom(array $plan): int
    {
        return max(0, $this->requiredInteger($plan, 'free_summary_uses_per_day', 'free'));
    }

    private function entitlementEnabled(array $plan, string $feature): bool
    {
        $entitlements = $plan['entitlements'] ?? null;

        if (! is_array($entitlements) || ! array_key_exists($feature, $entitlements)) {
            throw new RuntimeException("Plan [{$plan['key']}] entitlement [{$feature}] is not configured. Update Pricing Manager before allowing usage.");
        }

        return (bool) $entitlements[$feature];
    }

    /**
     * @return array<int, string>
     */
    private function exportEntitlements(array $plan): array
    {
        $entitlements = $plan['entitlements'] ?? null;

        if (! is_array($entitlements) || ! array_key_exists('exports', $entitlements) || ! is_array($entitlements['exports'])) {
            throw new RuntimeException("Plan [{$plan['key']}] export entitlements are not configured. Update Pricing Manager before allowing exports.");
        }

        return array_values(array_filter($entitlements['exports'], is_string(...)));
    }

    private function requiredInteger(array $plan, string $field, string $key): int
    {
        $value = $plan[$field] ?? null;

        if (! is_numeric($value)) {
            throw new RuntimeException("Pricing plan [{$key}] field [{$field}] is not configured. Update Pricing Manager before billing users.");
        }

        return (int) $value;
    }

    private function requiredString(array $plan, string $field, string $key): string
    {
        $value = $plan[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Pricing plan [{$key}] field [{$field}] is not configured. Update Pricing Manager before billing users.");
        }

        return $value;
    }

    private function ratePerUnit(string $feature): float
    {
        return $this->plans->ratePerUnit($feature);
    }
}
