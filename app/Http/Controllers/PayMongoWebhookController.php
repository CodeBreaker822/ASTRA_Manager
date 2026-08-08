<?php

namespace App\Http\Controllers;

use App\Models\BillingTransaction;
use App\Services\Billing\WalletTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        if (! is_string($eventType)) {
            Log::info('PayMongo webhook accepted without event type.', [
                'payload_event_id' => data_get($payload, 'data.id'),
            ]);

            return response()->json(['message' => 'Ignored.']);
        }

        if (! in_array($eventType, ['checkout_session.payment.paid', 'link.payment.paid', 'payment.paid', 'payment.failed'], true)) {
            Log::info('PayMongo webhook event ignored.', [
                'event_type' => $eventType,
                'payload_event_id' => data_get($payload, 'data.id'),
            ]);

            return response()->json(['message' => 'Ignored.']);
        }

        $resource = data_get($payload, 'data.attributes.data', []);
        $reference = $this->resourceReference($resource);
        $sessionId = $this->resourceSessionId($eventType, $resource);
        $paymentId = $this->resourcePaymentId($resource);
        $transaction = $this->findTransaction($reference, $sessionId, $paymentId, data_get($resource, 'attributes.metadata.billing_transaction_id'));

        if (! $transaction) {
            Log::info('PayMongo webhook transaction not tracked.', [
                'event_type' => $eventType,
                'reference' => $reference,
                'session_id' => $sessionId,
                'payment_id' => $paymentId,
                'transaction_id' => null,
                'credited' => false,
            ]);

            return response()->json(['message' => 'Transaction not tracked.']);
        }

        if ($transaction->plan !== 'wallet_topup') {
            Log::info('PayMongo webhook transaction plan ignored.', [
                'event_type' => $eventType,
                'reference' => $reference,
                'session_id' => $sessionId,
                'payment_id' => $paymentId,
                'transaction_id' => $transaction->id,
                'credited' => false,
                'plan' => $transaction->plan,
            ]);

            return response()->json(['message' => 'Transaction plan ignored.']);
        }

        if ($eventType === 'payment.failed') {
            $transaction->update([
                'payment_id' => is_string($paymentId) ? $paymentId : $transaction->payment_id,
                'payload' => $payload,
            ]);

            Log::info('PayMongo failed payment recorded.', [
                'event_type' => $eventType,
                'reference' => $reference,
                'session_id' => $sessionId,
                'payment_id' => $paymentId,
                'transaction_id' => $transaction->id,
                'credited' => false,
            ]);

            return response()->json(['message' => 'Payment failure recorded.']);
        }

        $credited = false;

        try {
            $credited = $topups->markPaid($transaction, is_string($paymentId) ? $paymentId : null, $payload);
        } catch (RuntimeException) {
            Log::info('PayMongo webhook transaction could not be credited.', [
                'event_type' => $eventType,
                'reference' => $reference,
                'session_id' => $sessionId,
                'payment_id' => $paymentId,
                'transaction_id' => $transaction->id,
                'credited' => false,
                'plan' => $transaction->plan,
            ]);

            return response()->json(['message' => 'Transaction ignored.']);
        }

        Log::info('PayMongo webhook payment recorded.', [
            'event_type' => $eventType,
            'reference' => $reference,
            'session_id' => $sessionId,
            'payment_id' => $paymentId,
            'transaction_id' => $transaction->id,
            'credited' => $credited,
        ]);

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

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    private function resourceReference(mixed $resource): ?string
    {
        foreach ([
            'attributes.reference_number',
            'attributes.external_reference_number',
            'attributes.metadata.reference',
            'attributes.metadata.pm_reference_number',
        ] as $key) {
            $value = data_get($resource, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resourceSessionId(string $eventType, mixed $resource): ?string
    {
        $resourceId = data_get($resource, 'id');
        $resourceType = data_get($resource, 'type');

        if (($eventType === 'checkout_session.payment.paid' || $resourceType === 'checkout_session') && is_string($resourceId)) {
            return $resourceId;
        }

        $sessionId = data_get($resource, 'attributes.checkout_session_id');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function resourcePaymentId(mixed $resource): ?string
    {
        $resourceId = data_get($resource, 'id');
        $resourceType = data_get($resource, 'type');

        if ($resourceType === 'payment' && is_string($resourceId) && $resourceId !== '') {
            return $resourceId;
        }

        $payments = data_get($resource, 'attributes.payments', []);

        if (! is_array($payments)) {
            return null;
        }

        foreach ($payments as $payment) {
            $paymentId = data_get($payment, 'id');

            if (is_string($paymentId) && $paymentId !== '') {
                return $paymentId;
            }
        }

        return null;
    }

    private function findTransaction(?string $reference, ?string $sessionId, ?string $paymentId, mixed $metadataTransactionId): ?BillingTransaction
    {
        if ($reference === null && $sessionId === null && $paymentId === null && ! is_numeric($metadataTransactionId)) {
            return null;
        }

        return BillingTransaction::query()
            ->where(function ($query) use ($reference, $sessionId, $paymentId, $metadataTransactionId): void {
                $query
                    ->when($reference !== null, fn ($query) => $query->orWhere('reference', $reference))
                    ->when($sessionId !== null, fn ($query) => $query->orWhere('checkout_session_id', $sessionId))
                    ->when($paymentId !== null, fn ($query) => $query->orWhere('payment_id', $paymentId))
                    ->when(is_numeric($metadataTransactionId), fn ($query) => $query->orWhere('id', (int) $metadataTransactionId));
            })
            ->first();
    }
}
