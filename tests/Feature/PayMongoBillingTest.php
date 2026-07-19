<?php

use App\Models\BillingTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('paymongo checkout creates a billing transaction and redirects to hosted checkout', function () {
    Http::fake([
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test_123',
                'attributes' => [
                    'checkout_url' => 'https://checkout.paymongo.com/cs_test_123',
                ],
            ],
        ]),
    ]);

    config([
        'services.paymongo.secret_key' => 'sk_test_123',
        'services.billing.pro_amount' => 190000,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'pro'])
        ->assertRedirect('https://checkout.paymongo.com/cs_test_123');

    $transaction = BillingTransaction::query()->firstOrFail();

    expect($transaction->user_id)->toBe($user->id)
        ->and($transaction->plan)->toBe('pro')
        ->and($transaction->provider)->toBe('paymongo')
        ->and($transaction->amount)->toBe(190000)
        ->and($transaction->checkout_session_id)->toBe('cs_test_123')
        ->and($transaction->status)->toBe('checkout_created');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
        && $request['data']['attributes']['metadata']['plan'] === 'pro'
        && $request['data']['attributes']['line_items'][0]['amount'] === 190000);
});

test('paymongo webhook upgrades the user plan after a verified paid event', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $user = User::factory()->create(['plan' => 'free']);
    $transaction = BillingTransaction::query()->create([
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'pro',
        'reference' => 'JERVA-1-PAID',
        'checkout_session_id' => 'cs_test_paid',
        'status' => 'checkout_created',
        'amount' => 190000,
        'currency' => 'PHP',
    ]);
    $payload = [
        'data' => [
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'data' => [
                    'id' => 'cs_test_paid',
                    'attributes' => [
                        'reference_number' => $transaction->reference,
                        'metadata' => ['plan' => 'pro'],
                        'payments' => [
                            ['id' => 'pay_test_123'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    $this->call(
        'POST',
        route('paymongo.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature}",
        ],
        $body,
    )->assertOk();

    expect($user->refresh()->plan)->toBe('pro')
        ->and($transaction->refresh()->status)->toBe('paid')
        ->and($transaction->payment_id)->toBe('pay_test_123')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('paymongo webhook rejects invalid signatures', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $this->withHeaders(['PayMongo-Signature' => 't=123,te=bad'])
        ->postJson(route('paymongo.webhook'), ['data' => []])
        ->assertUnauthorized();
});
