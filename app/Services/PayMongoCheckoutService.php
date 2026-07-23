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
     * @param  array<string, mixed>  $plan
     * @return array{session_id: string|null, checkout_url: string, payload: array<string, mixed>}
     */
    public function createCheckoutSession(User $user, string $planKey, array $plan, BillingTransaction $transaction, string $creditType = 'audio'): array
    {
        $secretKey = config('services.paymongo.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        $amount = $this->amountFor($planKey, $plan, $creditType);

        if ($amount <= 0) {
            throw new RuntimeException('PayMongo amount is not configured for this credit pack.');
        }
        $description = $this->descriptionFor($plan, $creditType);

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
                        'description' => $description,
                        'line_items' => [[
                            'name' => $description,
                            'amount' => $amount,
                            'currency' => 'PHP',
                            'quantity' => 1,
                        ]],
                        'metadata' => [
                            'user_id' => (string) $user->id,
                            'plan' => $planKey,
                            'credit_type' => $creditType,
                            'credit_minutes' => (string) ((int) ($plan['minutes'] ?? 0)),
                            'polish_characters' => (string) ((int) ($plan['polish_characters'] ?? 0)),
                            'summary_characters' => (string) ((int) ($plan['summary_characters'] ?? 0)),
                            'upload_price_per_hour' => (string) ((float) ($plan['upload_price_per_hour'] ?? 0)),
                            'live_price_per_hour' => (string) ((float) ($plan['live_price_per_hour'] ?? 0)),
                            'llm_price' => (string) ((float) ($plan['llm_price'] ?? 0)),
                            'polish_price_per_character' => (string) ((float) ($plan['polish_price_per_character'] ?? 0)),
                            'summary_price_per_character' => (string) ((float) ($plan['summary_price_per_character'] ?? 0)),
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

    /**
     * @param  array<string, mixed>|null  $plan
     */
    public function amountFor(string $planKey, ?array $plan = null, string $creditType = 'audio'): int
    {
        if ($planKey !== 'payg') {
            return 0;
        }

        if ($creditType === 'polish') {
            return $this->characterAmount(
                (int) ($plan['polish_characters'] ?? 0),
                (float) ($plan['polish_price_per_character'] ?? 0),
            );
        }

        if ($creditType === 'summary') {
            return $this->characterAmount(
                (int) ($plan['summary_characters'] ?? 0),
                (float) ($plan['summary_price_per_character'] ?? 0),
            );
        }

        $uploadPricePerHour = (float) ($plan['upload_price_per_hour'] ?? 0);
        $minutes = (int) ($plan['minutes'] ?? 0);
        if ($uploadPricePerHour > 0 && $minutes > 0) {
            return (int) round(($minutes / 60) * $uploadPricePerHour * 100);
        }

        return 0;
    }

    public function isConfiguredFor(string $planKey, string $creditType = 'audio'): bool
    {
        return is_string(config('services.paymongo.secret_key'))
            && config('services.paymongo.secret_key') !== ''
            && $this->amountFor($planKey, app(PlanService::class)->plan($planKey), $creditType) > 0;
    }

    private function characterAmount(int $characters, float $pricePerCharacter): int
    {
        if ($characters > 0 && $pricePerCharacter > 0) {
            return (int) round($characters * $pricePerCharacter * 100);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function descriptionFor(array $plan, string $creditType): string
    {
        return match ($creditType) {
            'polish' => 'JERVA '.number_format((int) ($plan['polish_characters'] ?? 0)).' polish character credits',
            'summary' => 'JERVA '.number_format((int) ($plan['summary_characters'] ?? 0)).' summarize character credits',
            default => 'JERVA '.(int) ($plan['minutes'] ?? 0).' transcription minute credits',
        };
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
}
