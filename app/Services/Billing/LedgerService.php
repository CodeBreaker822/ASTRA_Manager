<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Immutable wallet ledger service
 *
 * This service creates immutable audit trail records for all
 * wallet transactions. Every credit and debit must create
 * exactly one ledger entry.
 *
 * The ledger is the source of truth for reconciling balances
 * and investigating discrepancies.
 *
 * @property-read int $totalCredits Total credits in nanos
 * @property-read int $totalDebits Total debits in nanos
 */
class LedgerService
{
    /**
     * Transaction types
     */
    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Create a new ledger entry
     *
     * @param User $user The user associated with the transaction
     * @param string $type Transaction type (credit, debit, adjustment)
     * @param int $amountNanos Amount in nanos (positive for credit, negative for debit)
     * @param string $description Description of the transaction
     * @param string|null $referenceType Type of the reference (transcription, polish, summary, topup, adjustment)
     * @param int|null $referenceId ID of the reference
     * @param string|null $operationKey Idempotency key for this operation
     * @param int $balanceNanos Wallet balance after this transaction
     * @param array $metadata Additional metadata
     * @return WalletLedgerEntry The created ledger entry
     */
    public function createEntry(
        User $user,
        string $type,
        int $amountNanos,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $operationKey = null,
        int $balanceNanos = 0,
        array $metadata = []
    ): WalletLedgerEntry {
        return WalletLedgerEntry::create([
            'user_id' => $user->id,
            'user_wallet_id' => $user->wallet->id,
            'type' => $type,
            'description' => $description,
            'amount_nanos' => $amountNanos,
            'balance_nanos' => $balanceNanos,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'operation_key' => $operationKey,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get ledger entries for a user
     *
     * @param User $user The user
     * @param int $limit Number of entries to return
     * @param string|null $type Filter by type (credit, debit, adjustment)
     * @param string|null $referenceType Filter by reference type
     * @return \Illuminate\Support\Collection
     */
    public function getEntries(
        User $user,
        int $limit = 20,
        ?string $type = null,
        ?string $referenceType = null
    ): \Illuminate\Support\Collection {
        $query = WalletLedgerEntry::where('user_id', $user->id);

        if ($type) {
            $query->where('type', $type);
        }

        if ($referenceType) {
            $query->where('reference_type', $referenceType);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get ledger entries with pagination
     *
     * @param User $user The user
     * @param int $perPage Items per page
     * @param int|null $page Page number (null for all)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getPaginatedEntries(
        User $user,
        int $perPage = 20,
        ?int $page = null
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = WalletLedgerEntry::where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($page) {
            return $query->paginate($perPage, ['*'], 'page', $page);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get entry by operation key (for idempotency checks)
     *
     * @param string $operationKey The operation key
     * @return WalletLedgerEntry|null
     */
    public function getEntryByOperationKey(string $operationKey): ?WalletLedgerEntry
    {
        return WalletLedgerEntry::where('operation_key', $operationKey)->first();
    }

    /**
     * Reconcile wallet balance with ledger
     *
     * This checks if the current wallet balance matches the sum
     * of all ledger entries. Useful for detecting data corruption.
     *
     * @param User $user The user to reconcile
     * @return array ['matched' => bool, 'difference' => int, 'balance' => int]
     */
    public function reconcile(User $user): array
    {
        $entries = WalletLedgerEntry::where('user_id', $user->id)->get();
        $entryBalance = $entries->sum('amount_nanos');
        $actualBalance = $user->wallet->balance_nanos;

        return [
            'matched' => $entryBalance === $actualBalance,
            'difference' => $actualBalance - $entryBalance,
            'balance' => $actualBalance,
        ];
    }

    /**
     * Get summary statistics for a user's wallet
     *
     * @param User $user The user
     * @return array Summary statistics
     */
    public function getSummary(User $user): array
    {
        $entries = WalletLedgerEntry::where('user_id', $user->id);

        return [
            'total_transactions' => $entries->count(),
            'total_credits' => $entries->where('type', LedgerService::TYPE_CREDIT)->sum('amount_nanos'),
            'total_debits' => $entries->where('type', LedgerService::TYPE_DEBIT)->sum('amount_nanos'),
            'balance' => $user->wallet->balance_nanos,
            'reserved' => $user->wallet->reserved_nanos,
            'total_earned' => $user->wallet->total_earned_nanos,
            'total_spent' => $user->wallet->total_spent_nanos,
            'last_transaction' => $entries->orderBy('created_at', 'desc')->first(),
        ];
    }

    /**
     * Query ledger for analytics
     *
     * @param User $user The user
     * @param array $filters Query filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function query(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = WalletLedgerEntry::query();

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['reference_type'])) {
            $query->where('reference_type', $filters['reference_type']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['min_amount'])) {
            $query->where('amount_nanos', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount'])) {
            $query->where('amount_nanos', '<=', $filters['max_amount']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Batch create entries (for performance)
     *
     * @param array $entries Array of entry data
     * @return array ['success' => int, 'failed' => int, 'errors' => array]
     */
    public function batchCreate(array $entries): array
    {
        $success = 0;
        $failed = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($entries as $entry) {
                try {
                    $this->createEntry(
                        user: $entry['user'],
                        type: $entry['type'],
                        amountNanos: $entry['amount_nanos'],
                        description: $entry['description'],
                        referenceType: $entry['reference_type'] ?? null,
                        referenceId: $entry['reference_id'] ?? null,
                        operationKey: $entry['operation_key'] ?? null,
                        balanceNanos: $entry['balance_nanos'] ?? 0,
                        meta$entry['metadata'] ?? [],
                    );
                    $success++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'entry' => $entry,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Calculate running balance for a user
     *
     * @param User $user The user
     * @return array Array of [timestamp, balance] pairs
     */
    public function getRunningBalance(User $user): array
    {
        $entries = WalletLedgerEntry::where('user_id', $user->id)
            ->orderBy('created_at')
            ->get();

        $balance = 0;
        $runningBalance = [];

        foreach ($entries as $entry) {
            $balance += $entry->amount_nanos;
            $runningBalance[] = [
                'timestamp' => $entry->created_at->toIso8601String(),
                'balance_nanos' => $balance,
                'entry_id' => $entry->id,
                'type' => $entry->type,
                'amount_nanos' => $entry->amount_nanos,
            ];
        }

        return $runningBalance;
    }
}