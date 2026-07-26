<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PayMongoCheckoutService;
use App\Services\PlanService;
use App\Services\WalletTopupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function edit(
        Request $request,
        EntitlementService $entitlements,
        PayMongoCheckoutService $payMongo,
        PlanService $plans,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('settings/Billing', [
            'billing' => [
                'checkout_available' => $payMongo->isConfiguredForWalletTopup(),
            ],
            'entitlements' => $entitlements->summaryFor($user),
            'plans' => $plans->tiersForDisplay(),
            'topup' => [
                'wallet_currency' => 'USD',
                'checkout_currency' => 'PHP',
                'usd_to_php_rate' => $payMongo->walletTopupRateForDisplay(),
                'payment_method_types' => $payMongo->paymentMethodTypes(),
                'pass_on_fees' => (bool) config('services.paymongo.pass_on_fees', true),
            ],
            'walletBalance' => (int) round(((float) $user->wallet_balance) * 100),
        ]);
    }

    public function checkout(Request $request, PayMongoCheckoutService $payMongo): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $amountInCents = (int) round(((float) $validated['amount']) * 100);

        $transaction = BillingTransaction::query()->create([
            'user_id' => $user->id,
            'provider' => 'paymongo',
            'plan' => 'wallet_topup',
            'reference' => 'JERVA-'.$user->id.'-'.Str::upper(Str::random(12)),
            'status' => 'pending',
            'amount' => $amountInCents,
            'currency' => 'USD',
        ]);

        try {
            $checkout = $payMongo->createWalletTopupCheckout($user, $amountInCents, $transaction);
        } catch (RuntimeException $exception) {
            Log::warning('PayMongo checkout could not be created.', [
                'user_id' => $user->id,
                'amount' => $amountInCents,
                'error' => $exception->getMessage(),
            ]);

            $transaction->update([
                'status' => 'failed',
                'payload' => ['error' => $exception->getMessage()],
            ]);

            return back()->withErrors([
                'billing' => 'PayMongo checkout could not be started: '.$exception->getMessage(),
            ]);
        }

        DB::transaction(function () use ($transaction, $checkout): void {
            $transaction->update([
                'checkout_session_id' => $checkout['session_id'],
                'checkout_url' => $checkout['checkout_url'],
                'payload' => $checkout['payload'],
                'status' => 'checkout_created',
            ]);
        });

        return Inertia::location($checkout['checkout_url']);
    }

    public function success(
        Request $request,
        PayMongoCheckoutService $payMongo,
        WalletTopupService $topups,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $reference = $request->query('reference');

        if (! is_string($reference) || $reference === '') {
            return redirect()
                ->route('billing.edit')
                ->with('warning', 'Payment returned without a transaction reference. Credits will update when PayMongo confirms payment.');
        }

        $transaction = BillingTransaction::query()
            ->where('user_id', $user->id)
            ->where('reference', $reference)
            ->first();

        if (! $transaction) {
            return redirect()
                ->route('billing.edit')
                ->withErrors(['billing' => 'Payment transaction was not found.']);
        }

        if ($transaction->status === 'paid') {
            return redirect()
                ->route('billing.edit')
                ->with('success', 'Payment already confirmed. Credits are in your wallet.');
        }

        if (! is_string($transaction->checkout_session_id) || $transaction->checkout_session_id === '') {
            return redirect()
                ->route('billing.edit')
                ->withErrors(['billing' => 'Payment checkout session was not found.']);
        }

        try {
            $payload = $payMongo->retrieveCheckoutSession($transaction->checkout_session_id);
        } catch (RuntimeException $exception) {
            Log::warning('PayMongo checkout session could not be verified after return.', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'checkout_session_id' => $transaction->checkout_session_id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('billing.edit')
                ->with('warning', 'Payment was not verified yet. Credits will update when PayMongo confirms payment.');
        }

        if (! $payMongo->checkoutSessionIsPaid($payload)) {
            return redirect()
                ->route('billing.edit')
                ->with('warning', 'Payment is not confirmed yet. Credits will update when PayMongo confirms payment.');
        }

        $credited = $topups->markPaid($transaction, $payMongo->checkoutSessionPaymentId($payload), $payload);

        return redirect()
            ->route('billing.edit')
            ->with('success', $credited ? 'Payment confirmed. Credits added to your wallet.' : 'Payment already confirmed. Credits are in your wallet.');
    }

    public function cancel(): RedirectResponse
    {
        return redirect()
            ->route('billing.edit')
            ->withErrors(['billing' => 'PayMongo checkout was cancelled.']);
    }
}
