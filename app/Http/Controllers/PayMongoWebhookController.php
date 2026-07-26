<?php

namespace App\Http\Controllers;

use App\Models\BillingTransaction;
use App\Services\WalletTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, WalletTopupService $topups): JsonResponse
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

        if (! is_string($reference) && ! is_string($sessionId)) {
            return response()->json(['message' => 'Missing transaction reference.'], 422);
        }

        $transaction = BillingTransaction::query()
            ->where(function ($query) use ($reference, $sessionId): void {
                $query
                    ->when(is_string($reference), fn ($query) => $query->orWhere('reference', $reference))
                    ->when(is_string($sessionId), fn ($query) => $query->orWhere('checkout_session_id', $sessionId));
            })
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        if ($transaction->plan !== 'wallet_topup') {
            return response()->json(['message' => 'Unknown plan.'], 422);
        }

        try {
            $topups->markPaid($transaction, is_string($paymentId) ? $paymentId : null, $payload);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Unknown plan.'], 422);
        }

        return response()->json(['message' => 'Payment recorded. Credits added.']);
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
