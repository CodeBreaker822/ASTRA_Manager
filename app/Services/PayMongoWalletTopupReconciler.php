<?php

namespace App\Services;

use App\Models\BillingTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayMongoWalletTopupReconciler
{
    public function __construct(
        private readonly PayMongoCheckoutService $payMongo,
        private readonly WalletTopupService $topups,
    ) {}

    public function reconcileFor(User $user): int
    {
        if (! $this->payMongo->isConfiguredForWalletTopup()) {
            return 0;
        }

        $credited = 0;

        BillingTransaction::query()
            ->where('user_id', $user->id)
            ->where('provider', 'paymongo')
            ->where('plan', 'wallet_topup')
            ->whereIn('status', ['pending', 'checkout_created'])
            ->whereNotNull('checkout_session_id')
            ->latest()
            ->each(function (BillingTransaction $transaction) use (&$credited): void {
                try {
                    $payload = $this->payMongo->retrieveCheckoutSession((string) $transaction->checkout_session_id);
                } catch (RuntimeException $exception) {
                    Log::warning('PayMongo checkout session could not be reconciled.', [
                        'transaction_id' => $transaction->id,
                        'checkout_session_id' => $transaction->checkout_session_id,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                $paymentId = $this->payMongo->checkoutSessionPaymentId($payload);

                if ($paymentId !== null) {
                    $credited += $this->topups->markPaid($transaction, $paymentId, $payload) ? 1 : 0;

                    return;
                }

                $this->topups->recordCheckoutSnapshot($transaction, $payload);
            });

        return $credited;
    }
}
