<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PayMongoCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $plans = config('plans.tiers', []);
        $plans = is_array($plans) ? $plans : [];

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
            'plans' => collect($plans)
                ->map(fn (mixed $plan, string $key): array => array_merge(
                    ['key' => $key],
                    is_array($plan) ? $plan : [],
                ))
                ->values()
                ->all(),
        ]);
    }

    public function checkout(Request $request, PayMongoCheckoutService $payMongo): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:pro,team'],
        ]);
        $planKey = $validated['plan'];
        $plans = config('plans.tiers', []);
        $plan = is_array($plans) ? ($plans[$planKey] ?? null) : null;

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
            $transaction->update([
                'status' => 'failed',
                'payload' => ['error' => $exception->getMessage()],
            ]);

            return back()->withErrors([
                'billing' => $exception->getMessage(),
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
