<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PayMongoCheckoutService;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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
                'provider' => config('services.billing.provider'),
                'checkout_available' => $payMongo->isConfiguredForWalletTopup(),
                'portal_available' => false,
            ],
            'entitlements' => $entitlements->summaryFor($user),
            'plans' => $plans->tiersForDisplay(),
            'walletBalance' => $user->wallet_balance,
        ]);
    }

    public function checkout(Request $request, PayMongoCheckoutService $payMongo): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:100'], // Minimum $1 = 100 minor units
        ]);

        $amountInMinorUnits = $validated['amount'];

        DB::transaction(function () use ($user, $amountInMinorUnits) {
            $transaction = BillingTransaction::query()->create([
                'user_id' => $user->id,
                'provider' => 'paymongo',
                'plan' => 'wallet_topup',
                'reference' => 'JERVA-'.$user->id.'-'.Str::upper(Str::random(12)),
                'status' => 'pending',
                'amount' => $amountInMinorUnits,
                'currency' => 'USD',
            ]);

            try {
                $checkout = $payMongo->createWalletTopupCheckout($user, $amountInMinorUnits, $transaction);
            } catch (RuntimeException $exception) {
                Log::warning('PayMongo checkout could not be created.', [
                    'user_id' => $user->id,
                    'amount' => $amountInMinorUnits,
                    'error' => $exception->getMessage(),
                ]);

                $transaction->update([
                    'status' => 'failed',
                    'payload' => ['error' => 'Checkout could not be started.'],
                ]);

                throw $exception;
            }

            $transaction->update([
                'checkout_session_id' => $checkout['session_id'],
                'checkout_url' => $checkout['checkout_url'],
                'payload' => $checkout['payload'],
                'status' => 'checkout_created',
            ]);
        });

        return redirect()->away($checkout['checkout_url']);
    }

    public function success(): RedirectResponse
    {
        return redirect()
            ->route('billing.edit')
            ->with('success', 'PayMongo checkout completed. Your credits will appear after payment confirmation.');
    }

    public function cancel(): RedirectResponse
    {
        return redirect()
            ->route('billing.edit')
            ->withErrors(['billing' => 'PayMongo checkout was cancelled.']);
    }
}
