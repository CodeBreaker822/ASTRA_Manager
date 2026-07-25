<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Billing\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * PaymentController - Handles API requests for payment and credit management
 *
 * Security Features:
 * - All endpoints require authentication via Sanctum token
 * - No sensitive data (API keys) in responses or logs
 * - Credit balance is managed through service layer only
 * - Webhook endpoint is publicly accessible but signature-verified
 */
final class PaymentController extends Controller
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;

        // All payment endpoints require authentication
        $this->middleware('auth:sanctum');
    }

    /**
     * Get available credit packages for purchase
     *
     * GET /api/credits/packages
     *
     * @return JsonResponse
     */
    public function packages(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'packages' => $this->paymentService->getCreditPackages(),
            'minimum_purchase' => '₱' . number_format(50, 0),
        ]);
    }

    /**
     * Purchase credits with Paymongo
     *
     * POST /api/credits/purchase
     *
     * Request body:
     * {
     *   "payment_source_id": "source_XXXX",
     *   "amount": 50,
     *   "type": "card"
     * }
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_source_id' => ['required', 'string'],
            'amount' => ['required', 'integer'],
            'type' => ['nullable', 'string', 'in:card,gcash,grab_pay,paymaya'],
        ]);

        // Get authenticated user
        $user = $request->user();

        // Log the purchase attempt (but NOT sensitive payment details)
        Log::info('Credit purchase initiated', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'amount' => $validated['amount'],
            'type' => $validated['type'] ?? 'card',
        ]);

        try {
            // Create payment intent
            $result = $this->paymentService->createPaymentIntent(
                user: $user,
                sourceId: $validated['payment_source_id'],
                amount: $validated['amount'],
                type: $validated['type'] ?? 'card',
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment intent created successfully',
                'data' => [
                    'transaction_id' => $result['transaction_id'],
                    'reference' => $result['reference'],
                    'amount' => $result['amount'],
                    'credits' => $result['credits'],
                    'status' => $result['status'],
                    'checkout_url' => $result['checkout_url'],
                    'payment_intent_id' => $result['payment_intent_id'],
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Invalid purchase request', [
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'amount' => $e->getMessage(),
            ]);
        } catch (\RuntimeException $e) {
            Log::error('Payment processing failed', [
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'payment' => 'Failed to process payment: ' . $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error during payment purchase', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
            ], 500);
        }
    }

    /**
     * Get user's current credit balance
     *
     * GET /api/credits/balance
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();

        $balance = $this->paymentService->getUserBalance($user);

        return response()->json([
            'success' => true,
            'data' => $balance,
        ]);
    }

    /**
     * Get user's transaction history
     *
     * GET /api/credits/transactions
     *
     * Query parameters:
     * - limit: Number of transactions (default: 20, max: 100)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $limit = $validated['limit'] ?? 20;

        $history = $this->paymentService->getUserTransactionHistory($user, $limit);

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $history,
                'count' => count($history),
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Process Paymongo webhook events
     *
     * POST /api/credits/webhook
     *
     * This endpoint is publicly accessible and receives webhook events from Paymongo.
     * It verifies the webhook signature before processing payment confirmations.
     *
     * Headers:
     * - X-Paymongo-Signature: Webhook signature (required)
     *
     * Body: Raw Paymongo webhook payload
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Paymongo-Signature');

        if (empty($signature)) {
            Log::warning('Paymongo webhook received without signature', [
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook signature is required',
            ], 401);
        }

        $payload = $request->getContent();

        // Process the webhook (this will verify signature internally)
        $success = $this->paymentService->processWebhook($payload, $signature);

        if (! $success) {
            Log::warning('Paymongo webhook processing failed', [
                'signature' => substr($signature, 0, 10) . '...',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
            ], 500);
        }

        // Always return 200 OK to avoid Paymongo retrying
        return response()->json([
            'success' => true,
            'message' => 'Webhook processed successfully',
        ]);
    }
}