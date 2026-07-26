<?php

use App\Models\BillingTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

test('paymongo wallet top-up checkout creates a billing transaction and redirects to hosted checkout', function () {
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
        'services.paymongo.usd_to_php_rate' => 56.50,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['amount' => '10.00'])
        ->assertRedirect('https://checkout.paymongo.com/cs_test_123');

    $transaction = BillingTransaction::query()->firstOrFail();

    expect($transaction->user_id)->toBe($user->id)
        ->and($transaction->plan)->toBe('wallet_topup')
        ->and($transaction->provider)->toBe('paymongo')
        ->and($transaction->amount)->toBe(1000)
        ->and($transaction->currency)->toBe('USD')
        ->and($transaction->checkout_session_id)->toBe('cs_test_123')
        ->and($transaction->status)->toBe('checkout_created');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
        && $request['data']['attributes']['metadata']['plan'] === 'wallet_topup'
        && $request['data']['attributes']['metadata']['wallet_topup_amount'] === '1000'
        && $request['data']['attributes']['metadata']['wallet_topup_currency'] === 'USD'
        && $request['data']['attributes']['line_items'][0]['amount'] === 56500
        && $request['data']['attributes']['line_items'][0]['currency'] === 'PHP');
});

test('paymongo wallet top-up checkout records failed sessions without throwing an undefined variable error', function () {
    Http::fake([
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
            'errors' => [
                ['detail' => 'Invalid test checkout request.'],
            ],
        ], 422),
    ]);

    config(['services.paymongo.secret_key' => 'sk_test_123']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('billing.edit'))
        ->post(route('billing.checkout'), ['amount' => '5.00'])
        ->assertRedirect(route('billing.edit'))
        ->assertSessionHasErrors('billing');

    $transaction = BillingTransaction::query()->firstOrFail();

    expect($transaction->status)->toBe('failed')
        ->and($transaction->amount)->toBe(500);
});

test('paymongo webhook credits wallet after a verified paid wallet top-up event', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $user = User::factory()->create(['plan' => 'free']);
    $transaction = BillingTransaction::query()->create([
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'wallet_topup',
        'reference' => 'JERVA-1-PAID',
        'checkout_session_id' => 'cs_test_paid',
        'status' => 'checkout_created',
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    paymongoPaidWebhook($transaction, ['plan' => 'wallet_topup'])
        ->assertOk();

    expect((float) $user->refresh()->wallet_balance)->toBe(10.0)
        ->and($transaction->refresh()->status)->toBe('paid')
        ->and($transaction->payment_id)->toBe('pay_test_123')
        ->and($transaction->paid_at)->not->toBeNull();
});

test('paymongo webhook does not credit wallet twice for duplicate paid events', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $user = User::factory()->create(['plan' => 'free']);
    $transaction = BillingTransaction::query()->create([
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'wallet_topup',
        'reference' => 'JERVA-1-DUPLICATE',
        'checkout_session_id' => 'cs_test_duplicate',
        'status' => 'checkout_created',
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    paymongoPaidWebhook($transaction, ['plan' => 'wallet_topup'])->assertOk();
    paymongoPaidWebhook($transaction, ['plan' => 'wallet_topup'])->assertOk();

    expect($transaction->refresh()->status)->toBe('paid')
        ->and((float) $user->refresh()->wallet_balance)->toBe(10.0);
});

test('paymongo webhook rejects invalid signatures', function () {
    config(['services.paymongo.webhook_secret' => 'whsec_test']);

    $this->withHeaders(['PayMongo-Signature' => 't=123,te=bad'])
        ->postJson(route('paymongo.webhook'), ['data' => []])
        ->assertUnauthorized();
});

function paymongoPaidWebhook(BillingTransaction $transaction, array $metadata): TestResponse
{
    $payload = [
        'data' => [
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'data' => [
                    'id' => $transaction->checkout_session_id,
                    'attributes' => [
                        'reference_number' => $transaction->reference,
                        'metadata' => $metadata,
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

    return test()->call(
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
    );
}
