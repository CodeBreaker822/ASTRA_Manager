<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\API;
use App\Models\BillingOperation;
use App\Models\BillingTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class BillingService
{
    public function __construct(private readonly PlanService $plans) {}

    public function authorize(
        User $user,
        string $feature,
        int $units,
        string $idempotencyKey,
        ?API $api = null,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        array $metadata = [],
    ): BillingOperation {
        $this->validateFeature($feature);

        if ($units <= 0) {
            throw new InvalidArgumentException('Billable units must be greater than zero.');
        }

        if (blank($idempotencyKey)) {
            throw new InvalidArgumentException('An idempotency key is required.');
        }

        return DB::transaction(function () use (
            $user,
            $feature,
            $units,
            $idempotencyKey,
            $api,
            $subjectType,
            $subjectId,
            $metadata,
        ): BillingOperation {
            $existing = BillingOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id) {
                    throw new RuntimeException('Idempotency key belongs to another user.');
                }

                if ($existing->status === 'captured') {
                    return $existing;
                }

                if ($existing->status === 'authorized') {
                    return $existing;
                }
            }

            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            UsageRecord::query()->insertOrIgnore([
                'user_id' => $lockedUser->id,
                'period' => Carbon::now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            $allocation = $this->allocation($usage, $feature, $units);
            $rate = $this->plans->rateForFeature($feature);
            $rateNanos = Money::decimalToNanos($rate);
            $amountNanos = $this->costNanos($feature, $allocation['paid_units'], $rate);

            $availableNanos = max(
                0,
                (int) $lockedUser->wallet_balance_nanos
                - (int) $lockedUser->wallet_reserved_nanos,
            );

            if ($amountNanos > $availableNanos) {
                throw new InsufficientWalletBalanceException();
            }

            $this->reserveFreeAllowance(
                $usage,
                $feature,
                $allocation['free_units'],
            );

            if ($amountNanos > 0) {
                $lockedUser->increment('wallet_reserved_nanos', $amountNanos);
            }

            if ($existing && $existing->status === 'released') {
                $existing->forceFill([
                    'status' => 'authorized',
                    'feature' => $feature,
                    'requested_units' => $units,
                    'free_units' => $allocation['free_units'],
                    'paid_units' => $allocation['paid_units'],
                    'rate_nanos' => $rateNanos,
                    'authorized_amount_nanos' => $amountNanos,
                    'captured_amount_nanos' => 0,
                    'authorization_attempts' => (int) $existing->authorization_attempts + 1,
                    'metadata' => $metadata,
                    'result_payload' => null,
                    'authorized_at' => now(),
                    'captured_at' => null,
                    'released_at' => null,
                ])->save();

                return $existing->fresh();
            }

            return BillingOperation::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $lockedUser->id,
                'api_id' => $api?->id,
                'feature' => $feature,
                'status' => 'authorized',
                'idempotency_key' => $idempotencyKey,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId === null ? null : (string) $subjectId,
                'requested_units' => $units,
                'free_units' => $allocation['free_units'],
                'paid_units' => $allocation['paid_units'],
                'rate_nanos' => $rateNanos,
                'authorized_amount_nanos' => $amountNanos,
                'captured_amount_nanos' => 0,
                'currency' => 'PHP',
                'authorization_attempts' => 1,
                'metadata' => $metadata,
                'authorized_at' => now(),
            ]);
        });
    }

    public function charge(
        BillingOperation|string $operation,
        ?array $resultPayload = null,
    ): BillingOperation {
        $operationId = $operation instanceof BillingOperation
            ? $operation->id
            : $operation;

        return DB::transaction(function () use ($operationId, $resultPayload): BillingOperation {
            $lockedOperation = BillingOperation::query()
                ->whereKey($operationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOperation->status === 'captured') {
                return $lockedOperation;
            }

            if ($lockedOperation->status !== 'authorized') {
                throw new RuntimeException('Only an authorized billing operation can be charged.');
            }

            $lockedUser = User::query()
                ->whereKey($lockedOperation->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $usage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            $amountNanos = (int) $lockedOperation->authorized_amount_nanos;

            if ((int) $lockedUser->wallet_reserved_nanos < $amountNanos) {
                throw new RuntimeException('Reserved wallet amount is inconsistent.');
            }

            if ((int) $lockedUser->wallet_balance_nanos < $amountNanos) {
                throw new RuntimeException('Wallet balance is lower than its authorized charge.');
            }

            $this->captureUsage($usage, $lockedOperation);

            if ($amountNanos > 0) {
                $lockedUser->decrement('wallet_reserved_nanos', $amountNanos);
                $lockedUser->decrement('wallet_balance_nanos', $amountNanos);
                $lockedUser->increment('total_spent_nanos', $amountNanos);

                WalletLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'capture:'.$lockedOperation->id],
                    [
                        'id' => (string) Str::uuid(),
                        'user_id' => $lockedUser->id,
                        'billing_operation_id' => $lockedOperation->id,
                        'direction' => 'debit',
                        'type' => $lockedOperation->feature,
                        'amount_nanos' => $amountNanos,
                        'balance_after_nanos' => (int) $lockedUser->fresh()->wallet_balance_nanos,
                        'currency' => 'PHP',
                        'metadata' => [
                            'requested_units' => (int) $lockedOperation->requested_units,
                            'free_units' => (int) $lockedOperation->free_units,
                            'paid_units' => (int) $lockedOperation->paid_units,
                        ],
                    ],
                );
            }

            $lockedOperation->forceFill([
                'status' => 'captured',
                'captured_amount_nanos' => $amountNanos,
                'result_payload' => $resultPayload,
                'captured_at' => now(),
                'released_at' => null,
            ])->save();

            return $lockedOperation->fresh();
        });
    }

    public function release(
        BillingOperation|string $operation,
        ?string $reason = null,
    ): BillingOperation {
        $operationId = $operation instanceof BillingOperation
            ? $operation->id
            : $operation;

        return DB::transaction(function () use ($operationId, $reason): BillingOperation {
            $lockedOperation = BillingOperation::query()
                ->whereKey($operationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedOperation->status, ['released', 'captured'], true)) {
                return $lockedOperation;
            }

            $lockedUser = User::query()
                ->whereKey($lockedOperation->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $usage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            $this->releaseUsageReservation($usage, $lockedOperation);

            $amountNanos = (int) $lockedOperation->authorized_amount_nanos;

            if ($amountNanos > 0) {
                if ((int) $lockedUser->wallet_reserved_nanos < $amountNanos) {
                    throw new RuntimeException('Reserved wallet amount is inconsistent.');
                }

                $lockedUser->decrement('wallet_reserved_nanos', $amountNanos);
            }

            $metadata = is_array($lockedOperation->metadata)
                ? $lockedOperation->metadata
                : [];

            $metadata['release_reason'] = $reason;

            $lockedOperation->forceFill([
                'status' => 'released',
                'metadata' => $metadata,
                'released_at' => now(),
            ])->save();

            return $lockedOperation->fresh();
        });
    }

    public function creditWallet(
        User $user,
        int $amountNanos,
        string $idempotencyKey,
        ?BillingTransaction $transaction = null,
        array $metadata = [],
    ): WalletLedgerEntry {
        if ($amountNanos <= 0) {
            throw new InvalidArgumentException('Wallet credit must be greater than zero.');
        }

        return DB::transaction(function () use (
            $user,
            $amountNanos,
            $idempotencyKey,
            $transaction,
            $metadata,
        ): WalletLedgerEntry {
            $existing = WalletLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->increment('wallet_balance_nanos', $amountNanos);
            $lockedUser->increment('total_earned_nanos', $amountNanos);
            $lockedUser->refresh();

            return WalletLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $lockedUser->id,
                'billing_transaction_id' => $transaction?->id,
                'direction' => 'credit',
                'type' => 'wallet_topup',
                'amount_nanos' => $amountNanos,
                'balance_after_nanos' => (int) $lockedUser->wallet_balance_nanos,
                'currency' => 'PHP',
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);
        });
    }

    private function validateFeature(string $feature): void
    {
        if (! in_array($feature, ['upload', 'live', 'polish', 'summarize'], true)) {
            throw new InvalidArgumentException('Unknown billable feature.');
        }
    }

    private function costNanos(string $feature, int $paidUnits, string $rate): int
    {
        return match ($feature) {
            'upload', 'live' => Money::audioCostNanos($paidUnits, $rate),
            'polish', 'summarize' => Money::textCostNanos($paidUnits, $rate),
            default => throw new InvalidArgumentException('Unknown billable feature.'),
        };
    }

    private function allocation(UsageRecord $usage, string $feature, int $units): array
    {
        $freePlan = $this->plans->plan('free') ?? [];

        if (in_array($feature, ['upload', 'live'], true)) {
            $limit = max(0, (int) ($freePlan['minutes'] ?? 0) * 60);
            $consumed = min($limit, (int) $usage->seconds_transcribed);
            $remaining = max(
                0,
                $limit - $consumed - (int) $usage->free_seconds_reserved,
            );
            $freeUnits = min($units, $remaining);

            return [
                'free_units' => $freeUnits,
                'paid_units' => $units - $freeUnits,
            ];
        }

        $limit = $feature === 'polish'
            ? max(0, (int) ($freePlan['free_polish_uses_per_day'] ?? 0))
            : max(0, (int) ($freePlan['free_summary_uses_per_day'] ?? 0));

        $completed = $feature === 'polish'
            ? min($limit, (int) $usage->polish_count)
            : min($limit, (int) $usage->summary_count);

        $reserved = $feature === 'polish'
            ? (int) $usage->free_polish_reserved
            : (int) $usage->free_summary_reserved;

        $hasFreeAction = ($limit - $completed - $reserved) > 0;

        return [
            'free_units' => $hasFreeAction ? 1 : 0,
            'paid_units' => $hasFreeAction ? 0 : $units,
        ];
    }

    private function reserveFreeAllowance(
        UsageRecord $usage,
        string $feature,
        int $freeUnits,
    ): void {
        if ($freeUnits <= 0) {
            return;
        }

        match ($feature) {
            'upload', 'live' => $usage->increment('free_seconds_reserved', $freeUnits),
            'polish' => $usage->increment('free_polish_reserved'),
            'summarize' => $usage->increment('free_summary_reserved'),
            default => null,
        };
    }

    private function captureUsage(
        UsageRecord $usage,
        BillingOperation $operation,
    ): void {
        $feature = $operation->feature;
        $freeUnits = (int) $operation->free_units;

        if (in_array($feature, ['upload', 'live'], true)) {
            if ($freeUnits > 0) {
                $usage->decrement('free_seconds_reserved', $freeUnits);
            }

            $usage->increment('seconds_transcribed', (int) $operation->requested_units);

            return;
        }

        if ($feature === 'polish') {
            if ($freeUnits > 0) {
                $usage->decrement('free_polish_reserved');
            }

            $usage->increment('polish_count');

            return;
        }

        if ($feature === 'summarize') {
            if ($freeUnits > 0) {
                $usage->decrement('free_summary_reserved');
            }

            $usage->increment('summary_count');
        }
    }

    private function releaseUsageReservation(
        UsageRecord $usage,
        BillingOperation $operation,
    ): void {
        $freeUnits = (int) $operation->free_units;

        if ($freeUnits <= 0) {
            return;
        }

        match ($operation->feature) {
            'upload', 'live' => $usage->decrement('free_seconds_reserved', $freeUnits),
            'polish' => $usage->decrement('free_polish_reserved'),
            'summarize' => $usage->decrement('free_summary_reserved'),
            default => null,
        };
    }
}