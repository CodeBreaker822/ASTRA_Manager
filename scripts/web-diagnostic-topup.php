<?php

use App\Models\BillingTransaction;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = $argv[1] ?? '';
$amount = (int) ($argv[2] ?? 0);
$reference = $argv[3] ?? '';
$sessionId = $argv[4] ?? '';

if ($email === '' || $amount <= 0 || $reference === '' || $sessionId === '') {
    fwrite(STDERR, "Usage: php scripts/web-diagnostic-topup.php <email> <usd-cents> <reference> <checkout-session-id>\n");
    exit(1);
}

$user = User::query()->where('email', $email)->first();

if (! $user) {
    fwrite(STDERR, "Diagnostic user was not found.\n");
    exit(1);
}

$transaction = BillingTransaction::query()->updateOrCreate(
    ['reference' => $reference],
    [
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'wallet_topup',
        'checkout_session_id' => $sessionId,
        'status' => 'checkout_created',
        'amount' => $amount,
        'currency' => 'USD',
        'checkout_url' => 'https://checkout.paymongo.test/'.$sessionId,
        'payload' => [
            'diagnostic' => true,
            'message' => 'Created by web real workflow diagnostic.',
        ],
    ],
);

echo json_encode([
    'id' => $transaction->id,
    'reference' => $transaction->reference,
    'checkout_session_id' => $transaction->checkout_session_id,
    'amount' => $transaction->amount,
], JSON_THROW_ON_ERROR);
