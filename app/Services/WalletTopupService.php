<?php

namespace App\Services;

use App\Models\BillingTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletTopupService
{
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
            $user->forceFill([
                'wallet_balance' => round((float) $user->wallet_balance + ($lockedTransaction->amount / 100), 2),
            ])->save();

            return true;
        });
    }
}
