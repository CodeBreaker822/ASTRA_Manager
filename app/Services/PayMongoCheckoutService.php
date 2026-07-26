<?php

namespace App\Services;

use App\Models\BillingTransaction;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class PayMongoCheckoutService
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{session_id: string|null, checkout_url: string, payload: array<string, mixed>}
     */
    public function createWalletTopupCheckout(User $user, int $amountInMinorUnits, BillingTransaction $transaction): array
    {
        $secretKey = config('services.paymongo.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        if ($amountInMinorUnits <= 0) {
            throw new RuntimeException('PayMongo amount must be greater than 0.');
        }

        $payMongoAmount = $this->walletTopupPayMongoAmount($amountInMinorUnits);

        $response = $this->http
            ->withBasicAuth($secretKey, '')
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Idempotency-Key' => $transaction->reference,
            ])
            ->post($this->apiUrl().'/v2/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'billing' => [
                            'name' => $user->name,
                            'email' => $user->email,
                        ],
                        'description' => 'JERVA credit top-up',
                        'line_items' => [[
                            'name' => 'JERVA credit top-up',
                            'amount' => $payMongoAmount,
                            'currency' => 'PHP',
                            'quantity' => 1,
                        ]],
                        'metadata' => [
                            'user_id' => (string) $user->id,
                            'plan' => 'wallet_topup',
                            'wallet_topup_amount' => (string) $amountInMinorUnits,
                            'wallet_topup_currency' => 'USD',
                            'paymongo_amount' => (string) $payMongoAmount,
                            'paymongo_currency' => 'PHP',
                            'billing_transaction_id' => (string) $transaction->id,
                        ],
                        'payment_method_types' => $this->paymentMethodTypes(),
                        'reference_number' => $transaction->reference,
                        'send_email_receipt' => (bool) config('services.paymongo.send_email_receipt', true),
                        'success_url' => route('billing.success', [], true),
                        'cancel_url' => route('billing.cancel', [], true),
                    ],
                ],
            ]);

        if ($response->failed()) {
            $message = data_get($response->json(), 'errors.0.detail')
                ?? data_get($response->json(), 'errors.0.title')
                ?? 'PayMongo checkout session could not be created.';

            throw new RuntimeException((string) $message);
        }

        $payload = $response->json();
        $checkoutUrl = data_get($payload, 'data.attributes.checkout_url');

        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new RuntimeException('PayMongo did not return a checkout URL.');
        }

        $sessionId = data_get($payload, 'data.id');

        return [
            'session_id' => is_string($sessionId) ? $sessionId : null,
            'checkout_url' => $checkoutUrl,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    public function isConfiguredForWalletTopup(): bool
    {
        return is_string(config('services.paymongo.secret_key'))
            && config('services.paymongo.secret_key') !== '';
    }

    /**
     * @return list<string>
     */
    private function paymentMethodTypes(): array
    {
        $types = config('services.paymongo.payment_method_types', []);

        if (is_string($types)) {
            $types = explode(',', $types);
        }

        if (! is_array($types)) {
            return ['card', 'gcash', 'grab_pay', 'paymaya', 'qrph'];
        }

        return array_values(array_filter(array_map(
            fn (mixed $type): string => trim((string) $type),
            $types,
        )));
    }

    private function apiUrl(): string
    {
        $url = config('services.paymongo.api_url', 'https://api.paymongo.com');

        return rtrim(is_string($url) ? $url : 'https://api.paymongo.com', '/');
    }

    private function walletTopupPayMongoAmount(int $usdCents): int
    {
        $rate = (float) config('services.paymongo.usd_to_php_rate', 56.50);

        return (int) round(($usdCents / 100) * $rate * 100);
    }
}
