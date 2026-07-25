<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\BillingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Paymongo\PaymongoClient;

/**
 * PaymentService - Handles all payment-related operations with Paymongo
 *
 * Security Measures:
 * - API keys stored in config/services.php (never in logs or responses)
 * - Credits only added AFTER payment is confirmed successful
 * - Database transactions ensure atomicity
 * - Webhook signature verification prevents fraudulent credit claims
 * - Transaction logging for audit trails
 */
final class PaymentService
{
    private PaymongoClient $client;
    private string $publicKey;
    private string $webhookSecret;
    private int $creditsPerDollar;
    private const MIN_PURCHASE_AMOUNT = 50; // 0.50 PHP minimum
    private const CREDIT_PACKAGES = [
        50 => 5,   // PHP 50 for 5 credits
        100 => 10, // PHP 100 for 10 credits
        200 => 25, // PHP 200 for 25 credits
        500 => 50, // PHP 500 for 50 credits
    ];

    public function __construct()
    {
        $this->publicKey = (string) config('services.paymongo.public_key');
        $this->webhookSecret = (string) config('services.paymongo.webhook_secret');
        $this->creditsPerDollar = (int) config('services.paymongo.credits_per_dollar', 1);

        // Initialize Paymongo client with secret key (never exposed)
        $this->client = new PaymongoClient(config('services.paymongo.secret_key'));
    }

    /**
     * Get available credit packages for purchase
     */
    public function getCreditPackages(): array
    {
        return collect(self::CREDIT_PACKAGES)
            ->map(fn (int $credits, int $amount) => [
                'id' => "credit_pack_{$amount}",
                'name' => $this->getPackageName($amount),
                'price' => [
                    'amount' => $amount * 100, // Convert to centavos
                    'currency' => 'PHP',
                    'formatted' => '₱' . number_format($amount, 0),
                ],
                'credits' => $credits,
                'credits_per_php' => $credits / $amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Create a new payment intent for purchasing credits
     * This method creates a payment source and payment intent with Paymongo
     * but does NOT add credits to the user's wallet yet
     *
     * @param User $user The user making the purchase
     * @param string $sourceId The payment source ID from Paymongo
     * @param int $amount The amount to charge in PHP
     * @param string $type The payment method type (e.g., 'card', 'gcash')
     * @return array Payment information including checkout URL and transaction ID
     */
    public function createPaymentIntent(
        User $user,
        string $sourceId,
        int $amount,
        string $type = 'card',
    ): array {
        // Validate amount
        if (! array_key_exists($amount, self::CREDIT_PACKAGES)) {
            throw new \InvalidArgumentException(
                "Invalid amount. Must be one of: " . implode(', ', array_keys(self::CREDIT_PACKAGES))
            );
        }

        $credits = self::CREDIT_PACKAGES[$amount];

        DB::beginTransaction();
        try {
            // Create billing transaction record (pending status)
            $transaction = BillingTransaction::create([
                'user_id' => $user->id,
                'provider' => 'paymongo',
                'plan' => 'credits',
                'reference' => (string) Str::uuid(),
                'amount' => $amount,
                'currency' => 'PHP',
                'status' => 'pending',
            ]);

            // Create Payment Intent with Paymongo
            // Note: We don't verify yet - we only create the intent
            $paymentMethod = $this->client->paymentMethods->retrieve($sourceId);

            if (data_get($paymentMethod, 'data.attributes.status') !== 'succeeded') {
                DB::rollBack();
                throw new \RuntimeException('Payment source is not in succeeded status');
            }

            // Create payment intent
            $paymentIntentData = [
                'data' => [
                    'attributes' => [
                        'amount' => $amount * 100, // Convert to centavos
                        'currency' => 'PHP',
                        'payment_method' => $sourceId,
                        'statement_descriptor' => 'JERVA Credits - ' . $credits,
                        'confirm' => true, // Auto-confirm the payment
                        'capture_type' => 'automatic',
                    ],
                ],
            ];

            $paymentIntent = $this->client->paymentIntents->create($paymentIntentData);

            $paymentIntentId = data_get($paymentIntent, 'data.id');
            $paymentStatus = data_get($paymentIntent, 'data.attributes.status');
            $checkoutUrl = data_get($paymentIntent, 'data.attributes.checkout_url');

            // Update transaction with payment details
            $transaction->update([
                'payment_id' => $paymentIntentId,
                'status' => match ($paymentStatus) {
                    'succeeded' => 'processing',
                    'pending' => 'pending',
                    'failed' => 'failed',
                    'processing' => 'processing',
                    default => 'pending',
                },
                'checkout_session_id' => $paymentIntentId,
                'checkout_url' => $checkoutUrl,
                'payload' => [
                    'payment_intent_id' => $paymentIntentId,
                    'payment_status' => $paymentStatus,
                    'payment_method_type' => $type,
                    'credits' => $credits,
                ],
            ]);

            DB::commit();

            // Log the payment creation for audit
            Log::info('Payment intent created', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => $amount,
                'credits' => $credits,
                'payment_intent_id' => $paymentIntentId,
                'status' => $paymentStatus,
            ]);

            return [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => $amount,
                'credits' => $credits,
                'status' => $transaction->status,
                'checkout_url' => $checkoutUrl,
                'payment_intent_id' => $paymentIntentId,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create payment intent', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Process a webhook event from Paymongo
     * This verifies the webhook signature before processing payment confirmation
     *
     * @param string $payload The raw webhook payload
     * @param string $signature The webhook signature from X-Paymongo-Signature header
     * @return bool True if webhook was verified and processed successfully
     */
    public function processWebhook(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            Log::error('Paymongo webhook secret not configured');
            return false;
        }

        // Verify webhook signature to prevent fraud
        if (! $this->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Invalid Paymongo webhook signature', [
                'signature' => substr($signature, 0, 10) . '...',
            ]);
            return false;
        }

        // Decode the webhook payload
        $webhookEvent = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($webhookEvent) || ! isset($webhookEvent['data'])) {
            Log::warning('Invalid webhook payload structure');
            return false;
        }

        // Process payment events only
        $eventType = data_get($webhookEvent, 'data.attributes.type');
        if ($eventType !== 'payment.captured') {
            // Only process payment.captured events
            return true;
        }

        $paymentIntentId = data_get($webhookEvent, 'data.id');
        $paymentStatus = data_get($webhookEvent, 'data.attributes.status');

        // Only process successful payments
        if ($paymentStatus !== 'succeeded') {
            return true;
        }

        // Find transaction by payment_intent_id
        $transaction = BillingTransaction::where('checkout_session_id', $paymentIntentId)
            ->where('status', 'processing')
            ->first();

        if (! $transaction) {
            Log::warning('Payment intent not found in transactions', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return true;
        }

        // Get payment details from transaction payload
        $payloadData = $transaction->payload ?? [];
        $credits = (int) ($payloadData['credits'] ?? 0);
        $amount = $transaction->amount;

        if ($credits <= 0) {
            Log::error('Invalid credits amount in transaction', [
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'payload' => $payloadData,
            ]);
            return false;
        }

        DB::beginTransaction();
        try {
            // Update transaction as paid
            $transaction->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Add credits to user wallet (ONLY after verified successful payment)
            $transaction->user->increment('wallet_balance', $credits);
            $transaction->user->increment('total_earned_credits', $credits);

            DB::commit();

            // Log successful credit addition
            Log::info('Credits added to user wallet after successful payment', [
                'user_id' => $transaction->user->id,
                'user_email' => $transaction->user->email,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'credits_added' => $credits,
                'new_balance' => $transaction->user->wallet_balance,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add credits to user wallet', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Verify webhook signature using HMAC-SHA256
     * This prevents attackers from forging webhook callbacks
     *
     * @param string $payload The raw payload
     * @param string $signature The signature from X-Paymongo-Signature header
     * @return bool True if signature is valid
     */
    private function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

        // Paymongo sends signatures in format: id=..., created_at=...
        $parts = explode(',', $signature);

        foreach ($parts as $part) {
            if (str_starts_with($part, 'id=')) {
                $expectedIdSignature = substr($part, 3);
                if (hash_equals($expectedSignature, $expectedIdSignature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get user's current wallet balance
     *
     * @param User $user
     * @return array User's credit balance information
     */
    public function getUserBalance(User $user): array
    {
        return [
            'user_id' => $user->id,
            'wallet_balance' => $user->wallet_balance,
            'wallet_balance_formatted' => '₱' . number_format($user->wallet_balance, 2),
            'total_earned_credits' => $user->total_earned_credits,
            'total_spent_credits' => $user->total_spent_credits,
            'credits_per_php' => $this->creditsPerDollar,
            'minimum_purchase' => '₱' . number_format(self::MIN_PURCHASE_AMOUNT, 0),
        ];
    }

    /**
     * Get transaction history for a user
     *
     * @param User $user
     * @param int|null $limit Limit number of records
     * @return array Transaction history
     */
    public function getUserTransactionHistory(User $user, ?int $limit = 20): array
    {
        return $user->billingTransactions()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (BillingTransaction $transaction): array {
                $statusColor = match ($transaction->status) {
                    'paid' => 'green',
                    'processing' => 'blue',
                    'pending' => 'yellow',
                    'failed' => 'red',
                    default => 'gray',
                };

                $packageName = match ($transaction->plan) {
                    'credits' => 'Credit Purchase',
                    default => $transaction->plan,
                };

                return [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'provider' => $transaction->provider,
                    'plan' => $packageName,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'status' => $transaction->status,
                    'status_color' => $statusColor,
                    'paid_at' => $transaction->paid_at?->toIso8601String(),
                    'created_at' => $transaction->created_at->toIso8601String(),
                    'checkout_url' => $transaction->checkout_url,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Get package name based on amount
     */
    private function getPackageName(int $amount): string
    {
        return match ($amount) {
            50 => '₱50.00 (5 credits)',
            100 => '₱100.00 (10 credits)',
            200 => '₱200.00 (25 credits)',
            500 => '₱500.00 (50 credits)',
            default => 'Credit Package',
        };
    }
}