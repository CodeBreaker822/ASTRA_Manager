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
                'pro_checkout_url' => config('services.billing.pro_checkout_url'),
                'team_checkout_url' => config('services.billing.team_checkout_url'),
                'checkout_available' => $payMongo->isConfiguredFor('pro') || $payMongo->isConfiguredFor('team'),
                'portal_available' => false,
                'paymongo_ready' => [
                    'pro' => $payMongo->isConfiguredFor('pro'),
                    'team' => $payMongo->isConfiguredFor('team'),
                ],
            ],
            'entitlements' => $entitlements->summaryFor($user),
            'plans' => $plans->tiersForDisplay(),
        ]);
    }

    public function checkout(Request $request, PayMongoCheckoutService $payMongo, PlanService $plans): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:pro,team'],
        ]);
        $planKey = $validated['plan'];
        $plan = $plans->plan($planKey);

        if (! is_array($plan)) {
            return back()->withErrors([
                'billing' => 'Selected plan is not available.',
            ]);
        }

        if ($user->plan === $planKey) {
            return back()->with('success', 'That plan is already active.');
        }

        $transaction = BillingTransaction::query()->create([
            'user_id' => $user->id,
            'provider' => 'paymongo',
            'plan' => $planKey,
            'reference' => 'JERVA-'.$user->id.'-'.Str::upper(Str::random(12)),
            'status' => 'pending',
            'amount' => $payMongo->amountFor($planKey),
            'currency' => 'PHP',
        ]);

        try {
            $checkout = $payMongo->createCheckoutSession($user, $planKey, $plan, $transaction);
        } catch (RuntimeException $exception) {
            Log::warning('PayMongo checkout could not be created.', [
                'user_id' => $user->id,
                'plan' => $planKey,
                'error' => $exception->getMessage(),
            ]);

            $transaction->update([
                'status' => 'failed',
                'payload' => ['error' => 'Checkout could not be started.'],
            ]);

            return back()->withErrors([
                'billing' => 'Checkout could not be started. Please try again later.',
            ]);
        }

        $transaction->update([
            'checkout_session_id' => $checkout['session_id'],
            'checkout_url' => $checkout['checkout_url'],
            'payload' => $checkout['payload'],
            'status' => 'checkout_created',
        ]);

        return redirect()->away($checkout['checkout_url']);
    }

    public function success(): RedirectResponse
    {
        return redirect()
            ->route('billing.edit')
            ->with('success', 'PayMongo checkout completed. Your plan will update after payment confirmation.');
    }

    public function cancel(): RedirectResponse
    {
        return redirect()
            ->route('billing.edit')
            ->withErrors(['billing' => 'PayMongo checkout was cancelled.']);
    }
}
