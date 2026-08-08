<?php

namespace App\Services\Billing;

use App\Models\BillingTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletTopupService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function markPaid(BillingTransaction $transaction, ?string $paymentId, array $payload): bool
    {
        if ($transaction->plan !== 'wallet_topup') {
            throw new RuntimeException('Unknown billing transaction plan.');
        }

        return DB::transaction(function () use ($transaction, $paymentId, $payload): bool {
            $lockedTransaction = BillingTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyPaid = $lockedTransaction->status === 'paid';

            $lockedTransaction->update([
                'payment_id' => $paymentId ?: $lockedTransaction->payment_id,
                'status' => 'paid',
                'payload' => $payload,
                'paid_at' => $lockedTransaction->paid_at ?? Carbon::now(),
            ]);

            if ($alreadyPaid || $lockedTransaction->amount <= 0) {
                return false;
            }

            $user = $lockedTransaction->user()->lockForUpdate()->firstOrFail();
            $balanceCents = Money::decimalDollarsToUsdCents($user->wallet_balance);
            $user->forceFill([
                'wallet_balance' => Money::usdCentsToDecimalDollars($balanceCents + $lockedTransaction->amount),
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordCheckoutSnapshot(BillingTransaction $transaction, array $payload): void
    {
        if ($transaction->plan !== 'wallet_topup') {
            throw new RuntimeException('Unknown billing transaction plan.');
        }

        DB::transaction(function () use ($transaction, $payload): void {
            $lockedTransaction = BillingTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTransaction->status === 'paid') {
                return;
            }

            $remoteStatus = data_get($payload, 'data.attributes.status');

            $lockedTransaction->update([
                'payload' => $payload,
                'status' => $remoteStatus === 'expired' ? 'expired' : $lockedTransaction->status,
            ]);
        });
    }
}
