<?php

namespace App\Services\Billing;

use App\Models\BillingTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayMongoWalletTopupReconciler
{
    public function __construct(
        private readonly PayMongoCheckoutService $payMongo,
        private readonly WalletTopupService $topups,
    ) {}

    public function reconcileFor(User $user, int $limit = 10, float $budgetSeconds = 0.9): int
    {
        if (! $this->payMongo->isConfiguredForWalletTopup()) {
            return 0;
        }

        $credited = 0;
        $deadlineAt = microtime(true) + max(0.1, $budgetSeconds);

        BillingTransaction::query()
            ->where('user_id', $user->id)
            ->where('provider', 'paymongo')
            ->where('plan', 'wallet_topup')
            ->whereIn('status', ['pending', 'checkout_created'])
            ->whereNotNull('checkout_session_id')
            ->latest()
            ->limit(max(1, $limit))
            ->each(function (BillingTransaction $transaction) use (&$credited, $deadlineAt): false|null {
                if (microtime(true) >= $deadlineAt) {
                    return false;
                }

                $credited += $this->reconcileTransaction($transaction, $deadlineAt) ? 1 : 0;

                return null;
            });

        return $credited;
    }

    public function reconcileTransaction(BillingTransaction $transaction, ?float $deadlineAt = null): bool
    {
        if (! $this->payMongo->isConfiguredForWalletTopup()) {
            return false;
        }

        if ($transaction->plan !== 'wallet_topup' || $transaction->provider !== 'paymongo') {
            return false;
        }

        if (! in_array($transaction->status, ['pending', 'checkout_created'], true)) {
            return false;
        }

        if (! is_string($transaction->checkout_session_id) || $transaction->checkout_session_id === '') {
            return false;
        }

        if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
            return false;
        }

        try {
            $remaining = $deadlineAt === null ? 8.0 : max(0.2, $deadlineAt - microtime(true));
            $payload = $this->payMongo->retrieveCheckoutSession(
                $transaction->checkout_session_id,
                min(0.75, $remaining),
                min(0.25, $remaining),
            );
        } catch (Throwable $exception) {
            Log::warning('PayMongo checkout session could not be reconciled.', [
                'transaction_id' => $transaction->id,
                'checkout_session_id' => $transaction->checkout_session_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $paymentId = $this->payMongo->checkoutSessionPaymentId($payload);

        if ($paymentId !== null) {
            return $this->topups->markPaid($transaction, $paymentId, $payload);
        }

        $this->topups->recordCheckoutSnapshot($transaction, $payload);

        return false;
    }
}
