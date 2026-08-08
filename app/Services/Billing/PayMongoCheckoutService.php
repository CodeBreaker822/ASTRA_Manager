<?php

namespace App\Services\Billing;

use App\Models\BillingTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class PayMongoCheckoutService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CurrencyExchangeRateService $exchangeRates,
    ) {}

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

        $exchangeRate = $this->walletTopupRate();
        $payMongoAmount = $this->walletTopupPayMongoAmount($amountInMinorUnits, $exchangeRate);

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
                            'exchange_rate_usd_to_php' => (string) $exchangeRate,
                            'exchange_rate_source' => (string) config('exchange.default', 'frankfurter'),
                            'billing_transaction_id' => (string) $transaction->id,
                        ],
                        'payment_method_types' => $this->paymentMethodTypes(),
                        'reference_number' => $transaction->reference,
                        'send_email_receipt' => (bool) config('services.paymongo.send_email_receipt', true),
                        'pass_on_fees' => (bool) config('services.paymongo.pass_on_fees', true),
                        'success_url' => route('billing.success', ['reference' => $transaction->reference], true),
                        'cancel_url' => route('billing.cancel', ['reference' => $transaction->reference], true),
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
            && config('services.paymongo.secret_key') !== ''
            && $this->paymentMethodTypes() !== [];
    }

    public function walletTopupRateForDisplay(): ?float
    {
        try {
            return $this->walletTopupRate();
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $sessionId, float $timeoutSeconds = 8.0, float $connectTimeoutSeconds = 3.0): array
    {
        $secretKey = config('services.paymongo.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        $response = $this->http
            ->withBasicAuth($secretKey, '')
            ->acceptJson()
            ->timeout($timeoutSeconds)
            ->connectTimeout($connectTimeoutSeconds)
            ->get($this->apiUrl().'/v1/checkout_sessions/'.$sessionId);

        if ($response->failed()) {
            $message = data_get($response->json(), 'errors.0.detail')
                ?? data_get($response->json(), 'errors.0.title')
                ?? 'PayMongo checkout session could not be retrieved.';

            throw new RuntimeException((string) $message);
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkoutSessionIsPaid(array $payload): bool
    {
        return $this->checkoutSessionPaymentId($payload) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkoutSessionPaymentId(array $payload): ?string
    {
        $payments = data_get($payload, 'data.attributes.payments', []);

        if (! is_array($payments)) {
            return null;
        }

        foreach ($payments as $payment) {
            $status = data_get($payment, 'attributes.status', data_get($payment, 'status'));
            $paymentId = data_get($payment, 'id');

            if ($status === 'paid' && is_string($paymentId)) {
                return $paymentId;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function paymentMethodTypes(): array
    {
        $types = config('services.paymongo.payment_method_types', []);

        if (is_string($types)) {
            $types = explode(',', $types);
        }

        if (! is_array($types)) {
            return ['card', 'gcash', 'qrph'];
        }

        $types = array_values(array_filter(array_map(
            fn (mixed $type): string => trim((string) $type),
            $types,
        )));

        return $types !== [] ? $types : ['card', 'gcash', 'qrph'];
    }

    private function apiUrl(): string
    {
        $url = config('services.paymongo.api_url', 'https://api.paymongo.com');

        return rtrim(is_string($url) ? $url : 'https://api.paymongo.com', '/');
    }

    private function walletTopupPayMongoAmount(int $usdCents, float $rate): int
    {
        return Money::usdCentsToPhpCentavos($usdCents, $rate);
    }

    public function walletTopupRate(): float
    {
        return $this->exchangeRates->usdToPhpRate();
    }
}
