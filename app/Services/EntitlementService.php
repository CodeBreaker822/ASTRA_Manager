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
     * @return array{plan: array{key: string, name: string, minutes: int, free_polish_uses_per_day: int, free_summary_uses_per_day: int, features: mixed}, usage: array{period: string, seconds_transcribed: int, seconds_credit_balance: int, minutes_used: int, minutes_remaining: int, minutes_credit_balance: int, polish_count: int, summary_count: int, free_polish_remaining: int, free_summary_remaining: int, polish_credit_characters: int, summary_credit_characters: int}}
     */
    public function summaryFor(User $user): array
    {
        $usage = $this->usageForCurrentPeriod($user);
        $includedMinutes = $this->dailyFreeMinutes();
        $freePolishUses = $this->dailyFreePolishUses();
        $freeSummaryUses = $this->dailyFreeSummaryUses();
        $usedSeconds = (int) $usage->seconds_transcribed;
        $creditSeconds = (int) $user->credit_seconds;
        $usedMinutes = (int) ceil($usedSeconds / 60);
        $quotaMinutes = $includedMinutes + (int) floor($creditSeconds / 60);

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
                'seconds_credit_balance' => $creditSeconds,
                'minutes_used' => $usedMinutes,
                'minutes_remaining' => max(0, $quotaMinutes - $usedMinutes),
                'minutes_credit_balance' => (int) floor($creditSeconds / 60),
                'polish_count' => (int) $usage->polish_count,
                'summary_count' => (int) $usage->summary_count,
                'free_polish_remaining' => max(0, $freePolishUses - (int) $usage->polish_count),
                'free_summary_remaining' => max(0, $freeSummaryUses - (int) $usage->summary_count),
                'polish_credit_characters' => (int) $user->polish_credit_characters,
                'summary_credit_characters' => (int) $user->summary_credit_characters,
            ],
        ];
    }

    public function canTranscribe(User $user, int $additionalSeconds = 0): bool
    {
        $usage = $this->usageForCurrentPeriod($user);
        $quotaSeconds = ($this->dailyFreeMinutes() * 60) + (int) $user->credit_seconds;

        return (int) $usage->seconds_transcribed + $additionalSeconds <= $quotaSeconds;
    }

    public function recordTranscriptionUsage(User $user, int $seconds): void
    {
        $seconds = max(0, $seconds);

        if ($seconds === 0) {
            return;
        }

        DB::transaction(function () use ($user, $seconds): void {
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

            $freeSecondsRemaining = max(0, ($this->dailyFreeMinutes() * 60) - (int) $usage->seconds_transcribed);
            $creditSecondsToUse = min((int) $lockedUser->credit_seconds, max(0, $seconds - $freeSecondsRemaining));

            $usage->increment('seconds_transcribed', $seconds);

            if ($creditSecondsToUse > 0) {
                $lockedUser->decrement('credit_seconds', $creditSecondsToUse);
            }
        });
    }

    public function canPolish(User $user, int $characters): bool
    {
        return $this->canUseTextAction($user, 'polish', $characters);
    }

    public function canSummarize(User $user, int $characters): bool
    {
        return $this->canUseTextAction($user, 'summary', $characters);
    }

    public function recordPolishUsage(User $user, int $characters): void
    {
        $this->recordTextActionUsage($user, 'polish', $characters);
    }

    public function recordSummaryUsage(User $user, int $characters): void
    {
        $this->recordTextActionUsage($user, 'summary', $characters);
    }

    private function canUseTextAction(User $user, string $action, int $characters): bool
    {
        $usage = $this->usageForCurrentPeriod($user);
        $limit = $this->dailyFreeTextActionLimit($action);
        $countColumn = $this->textActionCountColumn($action);
        $creditColumn = $this->textActionCreditColumn($action);

        if ((int) $usage->{$countColumn} < $limit) {
            return true;
        }

        return (int) $user->{$creditColumn} >= max(0, $characters);
    }

    private function recordTextActionUsage(User $user, string $action, int $characters): void
    {
        $characters = max(0, $characters);

        DB::transaction(function () use ($user, $action, $characters): void {
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

            $countColumn = $this->textActionCountColumn($action);
            $creditColumn = $this->textActionCreditColumn($action);
            $freeUsesRemaining = max(0, $this->dailyFreeTextActionLimit($action) - (int) $usage->{$countColumn});

            if ($freeUsesRemaining <= 0 && $characters > (int) $lockedUser->{$creditColumn}) {
                throw new \RuntimeException('Not enough text credits.');
            }

            $usage->increment($countColumn);

            if ($freeUsesRemaining <= 0 && $characters > 0) {
                $lockedUser->decrement($creditColumn, $characters);
            }
        });
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

    private function dailyFreeTextActionLimit(string $action): int
    {
        return match ($action) {
            'polish' => $this->dailyFreePolishUses(),
            'summary' => $this->dailyFreeSummaryUses(),
            default => 0,
        };
    }

    private function textActionCountColumn(string $action): string
    {
        return match ($action) {
            'polish' => 'polish_count',
            'summary' => 'summary_count',
            default => throw new \InvalidArgumentException('Unknown text action.'),
        };
    }

    private function textActionCreditColumn(string $action): string
    {
        return match ($action) {
            'polish' => 'polish_credit_characters',
            'summary' => 'summary_credit_characters',
            default => throw new \InvalidArgumentException('Unknown text action.'),
        };
    }

    /**
     * Check if the user can afford to charge for a feature.
     * This check is not thread-safe - use charge() for atomic operations.
     *
     * @param User $user The user
     * @param string $feature Feature name (upload, live, polish, summarize)
     * @param float $units Number of units (e.g., hours, characters)
     * @return bool True if the user can afford it
     */
    public function canAfford(User $user, string $feature, float $units): bool
    {
        $rate = $this->ratePerUnit($feature);

        if ($rate === null) {
            return false;
        }

        $cost = round($units * $rate, 2);
        $freeAllowanceRemaining = $this->getFreeAllowanceRemaining($user, $feature, $units);

        if ($freeAllowanceRemaining) {
            return true;
        }

        return $user->wallet_balance >= $cost;
    }

    /**
     * Charge the user for a feature.
     * Deducts from free allowance first, then from wallet balance.
     * Uses atomic lock for thread-safe credit deduction.
     *
     * @param User $user The user
     * @param string $feature Feature name (upload, live, polish, summarize)
     * @param float $units Number of units (e.g., hours, characters)
     * @return void
     * @throws InsufficientWalletBalanceException If not enough balance after free allowance
     */
    public function charge(User $user, string $feature, float $units): void
    {
        $rate = $this->ratePerUnit($feature);

        if ($rate === null) {
            throw new InsufficientWalletBalanceException("Invalid feature rate for: {$feature}");
        }

        $cost = round($units * $rate, 2);

        // 1. Check free allowance first
        $freeAllowanceRemaining = $this->getFreeAllowanceRemaining($user, $feature, $units);

        if ($freeAllowanceRemaining > 0) {
            $this->consumeFreeAllowance($user, $feature, $units);
            return;
        }

        // 2. Atomic wallet balance deduction with row locking
        DB::transaction(function () use ($user, $cost, $feature) {
            // Use explicit lockForUpdate to prevent race conditions
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Double-check balance after acquiring lock (in case another transaction committed)
            if ($lockedUser->wallet_balance < $cost) {
                throw new InsufficientWalletBalanceException();
            }

            // Atomic decrement (fails if balance goes negative)
            $lockedUser->decrement('wallet_balance', $cost);

            // Increment total spent for tracking
            $lockedUser->increment('total_spent_credits', $cost);
        });
    }

    /**
     * Get the remaining free allowance for a feature.
     *
     * @param User $user The user
     * @param string $feature Feature name
     * @param float $units Total units being used
     * @return int Remaining free units
     */
    private function getFreeAllowanceRemaining(User $user, string $feature, float $units): int
    {
        $usage = $this->usageForCurrentPeriod($user);

        return match ($feature) {
            'upload', 'live' => $this->getFreeMinutesRemaining($user, $usage, (int) $units),
            'polish', 'summarize' => $this->getFreeTextActionRemaining($user, $usage, $feature, (int) $units),
            default => 0,
        };
    }

    /**
     * Get remaining free minutes for upload/live features.
     *
     * @param User $user The user
     * @param UsageRecord $usage The usage record
     * @param int $additionalSeconds Additional seconds being used
     * @return int Remaining free seconds
     */
    private function getFreeMinutesRemaining(User $user, UsageRecord $usage, int $additionalSeconds): int
    {
        $freeMinutes = $this->dailyFreeMinutes() * 60;
        $usedSeconds = (int) $usage->seconds_transcribed + $additionalSeconds;

        return max(0, $freeMinutes - $usedSeconds);
    }

    /**
     * Get remaining free text action uses.
     *
     * @param User $user The user
     * @param UsageRecord $usage The usage record
     * @param string $feature Feature name (polish or summarize)
     * @param int $additionalCharacters Additional characters being used
     * @return int Remaining free characters
     */
    private function getFreeTextActionRemaining(User $user, UsageRecord $usage, string $feature, int $additionalCharacters): int
    {
        $freeUses = $this->dailyFreeTextActionLimit($feature);
        $countColumn = $this->textActionCountColumn($feature);
        $usedCount = (int) $usage->{$countColumn};

        // Free uses are per-count, not per-character, so we check the count
        if ($usedCount < $freeUses) {
            $freeLimitChars = match ($feature) {
                'polish' => $this->dailyFreePolishUses() * 1000, // Assume 1000 chars per free use
                'summarize' => $this->dailyFreeSummaryUses() * 1000,
                default => 0,
            };

            return max(0, $freeLimitChars - $additionalCharacters);
        }

        return 0;
    }

    /**
     * Consume the free allowance for a feature.
     *
     * @param User $user The user
     * @param string $feature Feature name
     * @param float $units Units being used
     * @return void
     */
    private function consumeFreeAllowance(User $user, string $feature, float $units): void
    {
        $usage = $this->usageForCurrentPeriod($user);

        DB::transaction(function () use ($user, $usage, $feature, $units) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUsage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $lockedUsage) {
                $lockedUsage = UsageRecord::query()->create([
                    'user_id' => $lockedUser->id,
                    'period' => Carbon::now()->toDateString(),
                ]);
            }

            match ($feature) {
                'upload', 'live' => $lockedUsage->increment('seconds_transcribed', (int) $units),
                'polish' => $lockedUsage->increment('polish_count'),
                'summarize' => $lockedUsage->increment('summary_count'),
                default => null,
            };
        });
    }
}
