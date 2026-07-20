<?php

namespace App\Http\Controllers;

use App\Models\BillingTransaction;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, PlanService $plans): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $eventType = data_get($payload, 'data.attributes.type');

        if (! in_array($eventType, ['checkout_session.payment.paid', 'link.payment.paid'], true)) {
            return response()->json(['message' => 'Ignored.']);
        }

        $resource = data_get($payload, 'data.attributes.data');
        $reference = data_get($resource, 'attributes.reference_number');
        $sessionId = data_get($resource, 'id');
        $paymentId = data_get($resource, 'attributes.payments.0.id');
        $plan = data_get($resource, 'attributes.metadata.plan');

        $transaction = BillingTransaction::query()
            ->when(is_string($reference), fn ($query) => $query->orWhere('reference', $reference))
            ->when(is_string($sessionId), fn ($query) => $query->orWhere('checkout_session_id', $sessionId))
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        $plan = is_string($plan) && $plan !== '' ? $plan : $transaction->plan;

        if ($plans->plan($plan) === null) {
            return response()->json(['message' => 'Unknown plan.'], 422);
        }

        $transaction->update([
            'payment_id' => is_string($paymentId) ? $paymentId : $transaction->payment_id,
            'status' => 'paid',
            'payload' => $payload,
            'paid_at' => Carbon::now(),
        ]);

        $transaction->user()->update(['plan' => $plan]);

        return response()->json(['message' => 'Payment recorded.']);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = config('services.paymongo.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = $request->header('Paymongo-Signature', $request->header('PayMongo-Signature', ''));

        if ($header === '') {
            return false;
        }

        $parts = collect(explode(',', $header))
            ->mapWithKeys(function (string $part): array {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');

                return [trim($key) => trim($value)];
            });

        $timestamp = (string) $parts->get('t', '');
        $testSignature = (string) $parts->get('te', '');
        $liveSignature = (string) $parts->get('li', '');
        $signature = $liveSignature !== '' ? $liveSignature : $testSignature;

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
