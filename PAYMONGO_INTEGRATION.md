# Paymongo Credit Purchase Integration

This document describes the Paymongo integration for purchasing credits in the JERVA transcription platform.

## Overview

Users can now purchase credits using Paymongo's payment gateway. Upon successful payment confirmation, credits are automatically added to their wallet balance.

## Security Features

### 🔒 API Key Security
- Secret key stored in `config/services.php` (never exposed in logs or responses)
- Public key used for frontend checkout URLs only
- No sensitive data in API responses

### 🛡️ Payment Verification
- Credits are ONLY added AFTER payment is verified successful
- Webhook signature verification prevents fraudulent credit claims
- Database transactions ensure atomicity
- All credit additions are logged for audit trails

### 🚫 Preventing Credit Theft
- Credits can only be modified through the PaymentService
- No direct database manipulation of wallet_balance
- Webhook signature validation using HMAC-SHA256
- Payment must be in "succeeded" status before crediting

## Database Schema

### Users Table
Added columns for credit tracking:
- `wallet_balance` (DECIMAL, 15,2) - Current wallet balance in PHP
- `total_earned_credits` (INT) - Total credits earned from purchases
- `total_spent_credits` (INT) - Total credits spent

### BillingTransactions Table
Tracks all payment transactions:
- `user_id` - Reference to user who made the purchase
- `provider` - Payment provider (e.g., "paymongo")
- `plan` - Plan type (e.g., "credits")
- `reference` - Unique transaction reference (UUID)
- `checkout_session_id` - Paymongo payment intent ID
- `payment_id` - Paymongo payment ID
- `status` - Transaction status (pending, processing, paid, failed)
- `amount` - Amount charged (PHP)
- `currency` - Currency (PHP)
- `checkout_url` - Payment checkout URL
- `payload` - JSON payload with payment details
- `paid_at` - Timestamp when payment was confirmed

## Credit Packages

Available credit packages:
- **₱50.00** = 5 credits
- **₱100.00** = 10 credits
- **₱200.00** = 25 credits
- **₱500.00** = 50 credits

All packages follow a 1:1 credit-to-PHP ratio (configurable in `.env`).

## API Endpoints

### Authentication Required

#### 1. Get Available Packages
```
GET /api/credits/packages
```
Returns available credit packages with prices and credit amounts.

#### 2. Purchase Credits
```
POST /api/credits/purchase
Authorization: Bearer {sanctum_token}
Content-Type: application/json

{
  "payment_source_id": "source_XXXXXXXXXX",
  "amount": 50,
  "type": "card"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Payment intent created successfully",
  "data": {
    "transaction_id": 1,
    "reference": "uuid-here",
    "amount": 50,
    "credits": 5,
    "status": "processing",
    "checkout_url": "https://checkout.paymongo.com/...",
    "payment_intent_id": "pay_intent_XXXXXXX"
  }
}
```

#### 3. Get Wallet Balance
```
GET /api/credits/balance
Authorization: Bearer {sanctum_token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user_id": 1,
    "wallet_balance": "500.00",
    "wallet_balance_formatted": "₱500.00",
    "total_earned_credits": 500,
    "total_spent_credits": 0,
    "credits_per_php": 1,
    "minimum_purchase": "₱50"
  }
}
```

#### 4. Get Transaction History
```
GET /api/credits/transactions?limit=20
Authorization: Bearer {sanctum_token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "transactions": [...],
    "count": 10,
    "limit": 20
  }
}
```

### Public Endpoints (No Authentication)

#### 5. Process Webhook
```
POST /api/credits/webhook
```

**Headers:**
```
X-Paymongo-Signature: id=..., created_at=...
```

**Body:** Raw Paymongo webhook payload

This endpoint processes webhook events from Paymongo and verifies the signature before adding credits to user wallets.

## Webhook Event Handling

### Events Processed
Only `payment.captured` events are processed:

1. **Verify Webhook Signature** - Uses HMAC-SHA256 to validate webhook origin
2. **Check Payment Status** - Only successful payments get credits
3. **Find Transaction** - Locate transaction by payment_intent_id
4. **Add Credits** - Atomically add credits to user wallet
5. **Log Transaction** - Record successful credit addition

### Webhook Security
```php
// Signature verification prevents attackers from:
// - Forging webhook callbacks
// - Adding credits without actual payment
// - Manipulating payment status

$expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
```

## Configuration

### Environment Variables

Add to `.env`:
```env
PAYMONGO_SECRET_KEY=sk_test_...
PAYMONGO_PUBLIC_KEY=pk_test_...
PAYMONGO_WEBHOOK_SECRET=whsec_...
PAYMONGO_CREDITS_PER_DOLLAR=1
```

### Config File

Located in `config/services.php`:
```php
'paymongo' => [
    'api_url' => env('PAYMONGO_API_URL', 'https://api.paymongo.com'),
    'public_key' => env('PAYMONGO_PUBLIC_KEY'),
    'secret_key' => env('PAYMONGO_SECRET_KEY'),
    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
    'payment_method_types' => ['card'],  // Configure payment methods
    'send_email_receipt' => env('PAYMONGO_SEND_EMAIL_RECEIPT', true),
    'credits_per_dollar' => env('PAYMONGO_CREDITS_PER_DOLLAR', 1),
],
```

## Integration Workflow

### User Flow

1. **User opens app and clicks "Buy Credits"**
2. **App calls** `GET /api/credits/packages`
3. **User selects package** (e.g., ₱50 for 5 credits)
4. **App creates payment source** with Paymongo (Card, GCash, etc.)
5. **App calls** `POST /api/credits/purchase` with payment source ID
6. **Paymongo redirects user** to checkout page
7. **User completes payment** on Paymongo checkout
8. **Paymongo sends webhook** to `/api/credits/webhook`
9. **Server verifies signature** and checks payment status
10. **Server adds credits** to user's wallet balance
11. **User can now use credits** for transcription services

### Developer Flow (Client-Side)

```typescript
// 1. Get available packages
const packages = await fetch('/api/credits/packages').then(r => r.json());

// 2. Create payment source with Paymongo
const paymentSource = await paymongo.createPaymentSource({
  type: 'card',
  attributes: {
    amount: 5000,
    currency: 'PHP',
    details: {
      card_number: '4111111111111111',
      expiry: '12/25',
      cvc: '123',
    },
  },
});

// 3. Purchase credits
const purchase = await fetch('/api/credits/purchase', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    payment_source_id: paymentSource.id,
    amount: 50,
  }),
}).then(r => r.json());

// 4. Redirect to checkout
window.location.href = purchase.data.checkout_url;
```

### Developer Flow (Server-Side Webhook Handler)

```php
// Paymongo sends webhook to this endpoint automatically
Route::post('/credits/webhook', [PaymentController::class, 'webhook']);

// Controller processes webhook with signature verification
$success = $paymentService->processWebhook($payload, $signature);

if ($success) {
    Log::info('Credits added to user wallet');
} else {
    Log::warning('Webhook processing failed');
}
```

## Running the Migration

Run the database migration to add credit tracking columns:

```bash
php artisan migrate
```

## Setup Paymongo Webhook

1. **Get webhook secret** from Paymongo dashboard
2. **Add to `.env`:**
   ```env
   PAYMONGO_WEBHOOK_SECRET=whsec_...
   ```
3. **Configure webhook in Paymongo dashboard:**
   - Endpoint: `https://your-domain.com/api/credits/webhook`
   - Events to receive: `payment.captured`
   - Activate webhook

## Testing

### Test Credit Purchase Flow

1. **Create test payment source:**
   ```bash
   # Using Paymongo test keys (already in .env)
   # Use Paymongo test card: 4242 4242 4242 4242
   ```

2. **Purchase credits:**
   ```bash
   curl -X POST http://localhost:8000/api/credits/purchase \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "payment_source_id": "source_test_XXX",
       "amount": 50
     }'
   ```

3. **Verify webhook delivery:**
   ```bash
   # Check webhook logs
   tail -f storage/logs/laravel.log | grep "Credits added to user wallet"
   ```

4. **Check balance:**
   ```bash
   curl http://localhost:8000/api/credits/balance \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```

## Security Checklist

✅ **API keys stored in config** (not in code or logs)
✅ **Webhook signature verification** (prevents credit theft)
✅ **Payment status verification** (credits only after success)
✅ **Database transactions** (atomic operations)
✅ **Comprehensive logging** (audit trails)
✅ **Rate limiting on purchase endpoint** (prevent abuse)
✅ **Input validation** (secure data handling)
✅ **No direct SQL manipulation** (safe through ORM)

## Troubleshooting

### Credits not added after payment
1. Check webhook logs: `tail -f storage/logs/laravel.log | grep webhook`
2. Verify webhook URL is correct in Paymongo dashboard
3. Check payment status: `payment_status` should be "succeeded"
4. Verify signature: Check if `X-Paymongo-Signature` header is present

### Webhook not received
1. Check firewall allows incoming POST requests
2. Ensure webhook endpoint is accessible from internet
3. Verify webhook secret matches
4. Check Paymongo dashboard for webhook delivery status

### Payment source creation fails
1. Use valid test card: `4242 4242 4242 4242`
2. Verify expiration is in future
3. Check 3D Secure settings in Paymongo dashboard
4. Ensure payment method type is enabled

## Monitoring

### Key Logs to Monitor

1. **Payment Intent Created:**
   ```
   Payment intent created [user_id, user_email, amount, credits]
   ```

2. **Webhook Received:**
   ```
   Webhook received [signature, ip_address]
   ```

3. **Credits Added:**
   ```
   Credits added to user wallet [user_id, amount, credits_added, new_balance]
   ```

4. **Failed Attempts:**
   ```
   Invalid Paymongo webhook signature [signature_preview]
   Payment processing failed [user_id, error]
   ```

### Alert Thresholds

- **Failed webhooks** > 5 in 10 minutes
- **Multiple failed payment attempts** from same IP
- **Zero successful transactions** for > 1 hour
- **Large balance increase** without associated transaction

## Next Steps

### Potential Integrations

1. **Deduct credits on transcription usage**
   - Modify transcription service to check wallet balance
   - Deduct credits when processing audio files

2. **Credit expiration**
   - Add expiry date to user credits
   - Implement automatic cleanup of expired credits

3. **Credit history**
   - Add detailed credit usage logs
   - Show per-transcription credit cost

4. **Refund system**
   - Implement refund capability for failed transactions
   - Handle partial refunds

5. **Promotional codes**
   - Add discount codes for first-time purchases
   - Add referral bonus credits

## References

- [Paymongo PHP SDK GitHub](https://github.com/paymongo/paymongo-php)
- [Paymongo API Documentation](https://docs.paymongo.com/reference)
- [Paymongo Payment Methods](https://docs.paymongo.com/reference/payment-methods)
- [Paymongo Payment Intents](https://docs.paymongo.com/reference/payment-intents)