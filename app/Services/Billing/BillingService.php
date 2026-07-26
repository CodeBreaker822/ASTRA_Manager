<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\UserWallet;
use App\Models\BillingOperation;
use App\Models\PlanTier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Unified billing service with wallet integration
 *
 * This service provides the complete billing workflow:
 * 1. authorize() - Reserve funds before provider execution
 * 2. charge() - Charge wallet after successful operation
 * 3. release() - Release reserved funds if operation fails/cancels
 *
 * The billing system uses high-precision nanos for all monetary calculations:
 * - ₱1.00 = 1,000,000,000 nanos
 * - No floating-point arithmetic
 * - Immutable ledger for all transactions
 *
 * @property Money $userBalance Current wallet balance in nanos
 * @property Money $userReserved Reserved amount for current operation
 */
class BillingService
{
    /**
     * Supported billing features
     */
    public const FEATURE_TRANSCRIPTION = 'transcription';
    public const FEATURE_POLISH = 'polish';
    public const FEATURE_SUMMARY = 'summary';

    /**
     * Price field constants (from plan_tiers table)
     */
    public const RATE_UPLOAD_PER_HOUR = 'upload_price_per_hour';
    public const RATE_LIVE_PER_HOUR = 'live_price_per_hour';
    public const RATE_POLISH_PER_CHAR = 'polish_price_per_character';
    public const RATE_SUMMARY_PER_CHAR = 'summary_price_per_character';

    public function __construct(
        private readonly LedgerService $ledger
    ) {}

    /**
     * Get the user's wallet
     */
    private function getWallet(User $user): UserWallet
    {
        return $user->wallet ?? UserWallet::firstOrCreate([
            'user_id' => $user->id,
        ]);
    }

    /**
     * Get the wallet balance as Money
     */
    public function getBalance(User $user): Money
    {
        return Money::fromNanos($this->getWallet($user)->balance_nanos);
    }

    /**
     * Get the reserved amount as Money
     */
    public function getReserved(User $user): Money
    {
        return Money::fromNanos($this->getWallet($user)->reserved_nanos);
    }

    /**
     * Authorize funds before provider execution
     *
     * This reserves the exact amount needed for the operation
     * and creates a billing operation record.
     *
     * @param User $user The user requesting the operation
     * @param string $feature The feature being used (transcription, polish, summary)
     * @param int $units Requested units (seconds for transcription, characters for polish/summary)
     * @param string $operationKey Unique idempotency key to prevent double charges
     * @param string $referenceType Type of the resource being billed (e.g., 'transcript', 'clip')
     * @param int|null $referenceId ID of the resource being billed
     * @param array $metadata Additional metadata for the operation
     * @return BillingOperation The authorized billing operation
     * @throws RuntimeException If insufficient funds or authorization fails
     */
    public function authorize(
        User $user,
        string $feature,
        int $units,
        string $operationKey,
        string $referenceType = null,
        int $referenceId = null,
        array $metadata = []
    ): BillingOperation {
        // Check if operation already exists (idempotency)
        $existing = BillingOperation::where('operation_key', $operationKey)->first();
        if ($existing) {
            // If already authorized, return it
            if ($existing->status === 'authorized') {
                return $existing;
            }
            // If charged, refuse authorization
            throw new RuntimeException("Operation already charged: {$operationKey}");
        }

        // Get pricing rate
        $rateNanos = $this->getRate($feature, $units);
        $rate = Money::fromNanos((int) $rateNanos);

        // Calculate total cost
        $totalCost = $rate->multiply($units, PHP_ROUND_HALF_UP);

        // Check if user can afford it
        if ($this->getBalance($user)->lessThan($totalCost)) {
            throw new RuntimeException(
                "Insufficient funds. Required: {$totalCost}, Available: {$this->getBalance($user)}"
            );
        }

        DB::beginTransaction();

        try {
            // Reserve funds
            $wallet = $this->getWallet($user);
            $wallet->reserved_nanos += $totalCost->value;
            $wallet->save();

            // Create billing operation
            $operation = BillingOperation::create([
                'user_id' => $user->id,
                'feature' => $feature,
                'status' => 'authorized',
                'units_requested' => $units,
                'units_free' => 0,
                'units_paid' => 0,
                'rate_per_unit_nanos' => (string) $rateNanos,
                'authorized_amount_nanos' => $totalCost->value,
                'charged_amount_nanos' => null,
                'operation_key' => $operationKey,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'authorized_at' => now(),
            ]);

            DB::commit();

            return $operation;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Charge the wallet after successful operation
     *
     * Called after transcription, polish, or summarize operation succeeds.
     * Charges the exact amount reserved during authorization.
     *
     * @param BillingOperation $operation The authorized billing operation
     * @throws RuntimeException If charging fails or operation not authorized
     */
    public function charge(BillingOperation $operation): void
    {
        // Ensure operation is authorized
        if ($operation->status !== 'authorized') {
            throw new RuntimeException("Cannot charge non-authorized operation. Status: {$operation->status}");
        }

        // Check if already charged
        if ($operation->charged_at !== null) {
            throw new RuntimeException("Operation already charged: {$operation->operation_key}");
        }

        DB::beginTransaction();

        try {
            $wallet = $this->getWallet($operation->user);

            $authorizedAmount = Money::fromNanos($operation->authorized_amount_nanos);
            $currentBalance = $this->getBalance($operation->user);

            // Verify balance hasn't changed
            if ($currentBalance->value < $authorizedAmount->value) {
                throw new RuntimeException(
                    "Wallet balance changed. Reserved: {$authorizedAmount}, Available: {$currentBalance}"
                );
            }

            // Debit wallet
            $wallet->balance_nanos -= $authorizedAmount->value;
            $wallet->reserved_nanos -= $authorizedAmount->value;
            $wallet->total_spent_nanos += $authorizedAmount->value;
            $wallet->save();

            // Create ledger entry
            $this->ledger->createEntry(
                user: $operation->user,
                type: 'debit',
                amountNanos: -$authorizedAmount->value,
                description: "Charge for {$operation->feature}",
                referenceType: $operation->feature,
                referenceId: $operation->reference_id,
                operationKey: $operation->operation_key,
                balanceNanos: $wallet->balance_nanos,
                meta[
                    'operation_id' => $operation->id,
                    'units_requested' => $operation->units_requested,
                ]
            );

            // Update operation
            $operation->update([
                'status' => 'charged',
                'units_paid' => $operation->units_requested, // All units are paid after authorization
                'charged_amount_nanos' => $operation->authorized_amount_nanos,
                'charged_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Release reserved funds if operation fails/cancels
     *
     * Called when:
     * - Provider execution fails
     * - Operation is cancelled
     * - Operation times out
     *
     * @param BillingOperation $operation The authorized billing operation
     */
    public function release(BillingOperation $operation): void
    {
        // Ensure operation is authorized
        if ($operation->status !== 'authorized') {
            throw new RuntimeException("Cannot release non-authorized operation. Status: {$operation->status}");
        }

        DB::beginTransaction();

        try {
            $wallet = $this->getWallet($operation->user);

            $authorizedAmount = Money::fromNanos($operation->authorized_amount_nanos);

            // Only release if there are still reserved funds
            if ($wallet->reserved_nanos < $authorizedAmount->value) {
                // Funds already released or consumed
                DB::rollBack();
                return;
            }

            // Release funds
            $wallet->reserved_nanos -= $authorizedAmount->value;
            $wallet->save();

            // Create ledger entry
            $this->ledger->createEntry(
                user: $operation->user,
                type: 'credit',
                amountNanos: $authorizedAmount->value,
                description: "Release for {$operation->feature}",
                referenceType: $operation->feature,
                referenceId: $operation->reference_id,
                operationKey: $operation->operation_key,
                balanceNanos: $wallet->balance_nanos,
                meta[
                    'operation_id' => $operation->id,
                    'units_requested' => $operation->units_requested,
                ]
            );

            // Update operation
            $operation->update([
                'status' => 'released',
                'released_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get the rate per unit for a feature in nanos
     *
     * @param string $feature The feature (transcription, polish, summary)
     * @param int $units Requested units (affects which rate to use)
     * @return string Rate in nanos (as string to preserve precision)
     * @throws RuntimeException If rate not found or configuration invalid
     */
    private function getRate(string $feature, int $units): string
    {
        $rateField = match ($feature) {
            self::FEATURE_TRANSCRIPTION => $units > 0 ? self::RATE_UPLOAD_PER_HOUR : self::RATE_LIVE_PER_HOUR,
            self::FEATURE_POLISH => self::RATE_POLISH_PER_CHAR,
            self::FEATURE_SUMMARY => self::RATE_SUMMARY_PER_CHAR,
            default => throw new InvalidArgumentException("Unknown feature: {$feature}"),
        };

        $plan = $this->getPaygPlan();
        if (!$plan) {
            throw new RuntimeException("Pay-as-you-go plan not found");
        }

        $rateValue = $plan->{$rateField};

        if ($rateValue === null || $rateValue <= 0) {
            throw new RuntimeException("Invalid rate for {$feature}: {$rateValue}");
        }

        // Convert cents to nanos: 1 cent = 10,000,000 nanos
        return (string) ($rateValue * 10_000_000);
    }

    /**
     * Get the pay-as-you-go plan
     */
    private function getPaygPlan(): ?PlanTier
    {
        return PlanTier::where('key', 'payg')->first();
    }

    /**
     * Create a manual credit entry (e.g., for refunds, adjustments)
     *
     * @param User $user The user to credit
     * @param Money $amount Amount to credit in nanos
     * @param string $description Description of the credit
     * @param array $metadata Additional metadata
     */
    public function credit(
        User $user,
        Money $amount,
        string $description,
        array $metadata = []
    ): void {
        DB::beginTransaction();

        try {
            $wallet = $this->getWallet($user);
            $wallet->balance_nanos += $amount->value;
            $wallet->total_earned_nanos += $amount->value;
            $wallet->save();

            // Create ledger entry
            $this->ledger->createEntry(
                user: $user,
                type: 'credit',
                amountNanos: $amount->value,
                description: $description,
                referenceType: 'adjustment',
                referenceId: null,
                operationKey: null,
                balanceNanos: $wallet->balance_nanos,
                meta$metadata
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get wallet balance history (for debugging)
     *
     * @param User $user The user
     * @param int $limit Number of entries to return
     * @return \Illuminate\Support\Collection
     */
    public function getBalanceHistory(User $user, int $limit = 20): \Illuminate\Support\Collection
    {
        return $this->ledger->getEntries($user, $limit);
    }
}